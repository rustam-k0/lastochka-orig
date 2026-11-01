<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Jobs\FiscalizeRefundJob;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Task 13: webhook refund.succeeded — диспатч ATOL refund-чека вместо ЮKassa
 * receipt-sync, только если активный фискализатор ATOL (ЮKassa сама пробивает
 * чек возврата, повторный чек здесь не нужен).
 */
final class RefundFiscalizationTriggerTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->service = $this->app->make(PaymentService::class);
    }

    private function atolDriver(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => ['atol' => []],
        ]);
    }

    private function yookassaDriver(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'yookassa',
            'is_active' => true,
            'credentials' => [],
        ]);
    }

    /**
     * @return array{0: Order, 1: string, 2: string}
     */
    private function orderWithPendingRefundTransaction(float $total = 1000.0): array
    {
        $order = Order::factory()->create([
            'payment_type' => PaymentType::Card,
            'total' => $total,
            'captured_amount' => $total,
            'refunded_amount' => 0,
            'is_paid' => true,
        ]);

        $paymentId = 'pay_test_capture_'.$order->id;
        $refundId = 'rf_test_'.$order->id;

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => $total,
            'yookassa_payment_id' => $paymentId,
        ]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Refund,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => $total,
            'yookassa_payment_id' => $paymentId,
            'yookassa_refund_id' => $refundId,
        ]);

        return [$order, $paymentId, $refundId];
    }

    public function test_atol_refund_job_dispatched_and_receipt_sync_not_called(): void
    {
        $this->atolDriver();
        Queue::fake([FiscalizeRefundJob::class]);
        [$order, $paymentId, $refundId] = $this->orderWithPendingRefundTransaction(1000.0);

        $this->service->handleRefundSucceeded($refundId, $paymentId, 1000.0);

        Queue::assertPushed(FiscalizeRefundJob::class, function (FiscalizeRefundJob $job) use ($order, $refundId) {
            return $this->jobOrderId($job) === $order->id
                && abs($this->jobAmount($job) - 1000.0) < 0.001
                && $this->jobRefundId($job) === $refundId;
        });

        // ЮKassa receipt-sync не должен вызываться — иначе двойной чек возврата.
        $this->assertSame([], $this->gateway->callsFor('getReceipts'));

        $transaction = PaymentTransaction::where('yookassa_refund_id', $refundId)->first();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);

        $order->refresh();
        $this->assertEqualsWithDelta(1000.0, (float) $order->refunded_amount, 0.001);
    }

    public function test_yookassa_driver_does_not_dispatch_refund_job_and_syncs_receipt(): void
    {
        $this->yookassaDriver();
        Queue::fake([FiscalizeRefundJob::class]);
        [$order, $paymentId, $refundId] = $this->orderWithPendingRefundTransaction(1000.0);

        $this->service->handleRefundSucceeded($refundId, $paymentId, 1000.0);

        Queue::assertNotPushed(FiscalizeRefundJob::class);

        // Прежнее поведение сохранено: ЮKassa receipt-sync вызывается.
        $this->assertNotSame([], $this->gateway->callsFor('getReceipts'));

        $transaction = PaymentTransaction::where('yookassa_refund_id', $refundId)->first();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);

        $order->refresh();
        $this->assertEqualsWithDelta(1000.0, (float) $order->refunded_amount, 0.001);
    }

    public function test_no_fiscal_driver_configured_does_not_dispatch_refund_job(): void
    {
        // Нет ни одной активной IntegrationConfig(type=fiscal) — драйвер не задан.
        Queue::fake([FiscalizeRefundJob::class]);
        [, $paymentId, $refundId] = $this->orderWithPendingRefundTransaction(1000.0);

        $this->service->handleRefundSucceeded($refundId, $paymentId, 1000.0);

        Queue::assertNotPushed(FiscalizeRefundJob::class);
        $this->assertNotSame([], $this->gateway->callsFor('getReceipts'));
    }

    private function jobOrderId(FiscalizeRefundJob $job): int
    {
        return $this->readPrivateProperty($job, 'orderId');
    }

    private function jobAmount(FiscalizeRefundJob $job): float
    {
        return $this->readPrivateProperty($job, 'amount');
    }

    private function jobRefundId(FiscalizeRefundJob $job): ?string
    {
        return $this->readPrivateProperty($job, 'refundId');
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
