<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Jobs\FiscalizeSaleJob;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Services\Payment\PaymentService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Task 8: после успешного online (card) capture — диспатч ATOL sale-чека,
 * только если активный фискализатор ATOL (ЮKassa бьёт чек внутри платежа).
 *
 * Покрывает все три точки, где online-оплата ставит is_paid=true:
 *  - capturePayment() success-ветка,
 *  - handlePaymentSucceeded() основная ветка (новый capture),
 *  - handlePaymentSucceeded() race-ветка (capture уже записан раньше вебхука).
 */
final class OnlineFiscalizationTriggerTest extends TestCase
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
     * @return array{0: Order, 1: string}
     */
    private function cardOrderWithHeldTransaction(float $total = 1000.0): array
    {
        $order = Order::factory()->create([
            'payment_type' => PaymentType::Card,
            'total' => $total,
            'is_paid' => false,
        ]);

        $paymentId = 'pay_test_hold_'.$order->id;

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => $total,
            'yookassa_payment_id' => $paymentId,
        ]);

        return [$order, $paymentId];
    }

    // ------------------------------------------------------------------
    // handlePaymentSucceeded — основная ветка (новый capture)
    // ------------------------------------------------------------------

    public function test_atol_sale_job_dispatched_after_card_payment_succeeded(): void
    {
        $this->atolDriver();
        Queue::fake();
        [$order, $paymentId] = $this->cardOrderWithHeldTransaction(1000.0);

        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'succeeded',
            amount: '1000.00',
            currency: 'RUB',
        );

        $this->service->handlePaymentSucceeded($paymentId);

        Queue::assertPushed(FiscalizeSaleJob::class, function (FiscalizeSaleJob $job) use ($order) {
            return $this->jobOrderId($job) === $order->id
                && $this->jobPaymentType($job) === PaymentType::Card->value
                && abs($this->jobAmount($job) - 1000.0) < 0.001;
        });
    }

    public function test_yookassa_driver_does_not_dispatch_sale_job(): void
    {
        $this->yookassaDriver();
        Queue::fake();
        [, $paymentId] = $this->cardOrderWithHeldTransaction(1000.0);

        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'succeeded',
            amount: '1000.00',
            currency: 'RUB',
        );

        $this->service->handlePaymentSucceeded($paymentId);

        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    public function test_no_fiscal_driver_configured_does_not_dispatch_sale_job(): void
    {
        // Нет ни одной активной IntegrationConfig(type=fiscal) — драйвер не задан.
        Queue::fake();
        [, $paymentId] = $this->cardOrderWithHeldTransaction(1000.0);

        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'succeeded',
            amount: '1000.00',
            currency: 'RUB',
        );

        $this->service->handlePaymentSucceeded($paymentId);

        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    public function test_atol_sale_job_not_redispatched_when_payment_receipt_already_exists(): void
    {
        $this->atolDriver();
        Queue::fake();
        [$order, $paymentId] = $this->cardOrderWithHeldTransaction(1000.0);

        // Идемпотентность: чек прихода по заказу уже есть (например, диспатчен ранее).
        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => 'atol',
            'external_id' => 'atol-existing-1',
            'type' => 'payment',
            'status' => 'succeeded',
            'amount' => 1000.0,
            'items' => [],
            'raw' => [],
        ]);

        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'succeeded',
            amount: '1000.00',
            currency: 'RUB',
        );

        $this->service->handlePaymentSucceeded($paymentId);

        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    // ------------------------------------------------------------------
    // handlePaymentSucceeded — race-ветка (capture уже записан capturePayment'ом)
    // ------------------------------------------------------------------

    public function test_atol_sale_job_dispatched_on_race_between_capture_and_webhook(): void
    {
        $this->atolDriver();
        Queue::fake();
        [$order, $paymentId] = $this->cardOrderWithHeldTransaction(1000.0);

        // capturePayment уже отработал по этому payment_id ДО прихода вебхука.
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 1000.0,
            'yookassa_payment_id' => $paymentId,
        ]);

        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'succeeded',
            amount: '1000.00',
            currency: 'RUB',
        );

        $this->service->handlePaymentSucceeded($paymentId);

        Queue::assertPushed(FiscalizeSaleJob::class);
    }

    public function test_atol_sale_job_not_redispatched_on_race_when_payment_receipt_already_exists(): void
    {
        $this->atolDriver();
        Queue::fake();
        [$order, $paymentId] = $this->cardOrderWithHeldTransaction(1000.0);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 1000.0,
            'yookassa_payment_id' => $paymentId,
        ]);

        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => 'atol',
            'external_id' => 'atol-existing-2',
            'type' => 'payment',
            'status' => 'succeeded',
            'amount' => 1000.0,
            'items' => [],
            'raw' => [],
        ]);

        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'succeeded',
            amount: '1000.00',
            currency: 'RUB',
        );

        $this->service->handlePaymentSucceeded($paymentId);

        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    // ------------------------------------------------------------------
    // Реальная гонка capturePayment + вебхук по одному платежу: побочные
    // эффекты (capture-строка, order_paid, чек) — ровно один раз, их делает
    // тот, кто первым записал capture и перевёл заказ в оплаченные.
    // ------------------------------------------------------------------

    /**
     * Вебхук успел раньше: ЮKassa шлёт payment.succeeded сразу после capture, а
     * capturePayment открывает транзакцию только получив ответ шлюза. Прод-заказ
     * 450 (2026-09-25, GlitchTip 847): capture-строки 860 и 861 по одному платежу,
     * два order_paid, два FiscalizeSaleJob. Так было в 95 из 155 карточных заказов.
     */
    public function test_webhook_before_capture_payment_records_capture_and_dispatches_sale_once(): void
    {
        $this->atolDriver();
        Queue::fake();
        [$order, $paymentId] = $this->cardOrderWithHeldTransaction(1000.0);
        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'succeeded',
            amount: '1000.00',
            currency: 'RUB',
        );

        $this->service->handlePaymentSucceeded($paymentId);
        $result = $this->service->capturePayment($order, 1000.0);

        $this->assertTrue($result->success);
        $this->assertSame(1, $this->succeededCaptures($order, $paymentId), 'одна capture-строка на платёж');
        $this->assertSame(1, $this->orderPaidEvents($order), 'одно событие order_paid');
        Queue::assertPushed(FiscalizeSaleJob::class, 1);
    }

    /**
     * capturePayment успел раньше: вебхук прошёл раннюю проверку is_paid до его
     * коммита, дождался блокировки и попал в ветку «capture уже записан». Чек
     * уже поставил capturePayment — второй ставить нельзя.
     */
    public function test_capture_payment_before_webhook_dispatches_sale_once(): void
    {
        $this->atolDriver();
        Queue::fake();
        [$order, $paymentId] = $this->cardOrderWithHeldTransaction(1000.0);
        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'succeeded',
            amount: '1000.00',
            currency: 'RUB',
        );
        $this->gateway->beforePaymentInfo = fn () => $this->service->capturePayment($order->fresh(), 1000.0);

        $this->service->handlePaymentSucceeded($paymentId);

        $this->assertSame(1, $this->succeededCaptures($order, $paymentId), 'одна capture-строка на платёж');
        $this->assertSame(1, $this->orderPaidEvents($order), 'одно событие order_paid');
        Queue::assertPushed(FiscalizeSaleJob::class, 1);
    }

    private function succeededCaptures(Order $order, string $paymentId): int
    {
        return PaymentTransaction::where('order_id', $order->id)
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->where('yookassa_payment_id', $paymentId)
            ->count();
    }

    private function orderPaidEvents(Order $order): int
    {
        return EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_paid')
            ->count();
    }

    // ------------------------------------------------------------------
    // capturePayment — success-ветка
    // ------------------------------------------------------------------

    public function test_atol_sale_job_dispatched_after_capture_payment_success(): void
    {
        $this->atolDriver();
        Queue::fake();
        [$order] = $this->cardOrderWithHeldTransaction(1000.0);

        $this->service->capturePayment($order, 1000.0);

        Queue::assertPushed(FiscalizeSaleJob::class, function (FiscalizeSaleJob $job) use ($order) {
            return $this->jobOrderId($job) === $order->id
                && $this->jobPaymentType($job) === PaymentType::Card->value
                && abs($this->jobAmount($job) - 1000.0) < 0.001;
        });
    }

    public function test_yookassa_driver_does_not_dispatch_sale_job_after_capture_payment(): void
    {
        $this->yookassaDriver();
        Queue::fake();
        [$order] = $this->cardOrderWithHeldTransaction(1000.0);

        $this->service->capturePayment($order, 1000.0);

        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    public function test_atol_sale_job_not_redispatched_after_capture_payment_when_receipt_already_exists(): void
    {
        $this->atolDriver();
        Queue::fake();
        [$order] = $this->cardOrderWithHeldTransaction(1000.0);

        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => 'atol',
            'external_id' => 'atol-existing-3',
            'type' => 'payment',
            'status' => 'succeeded',
            'amount' => 1000.0,
            'items' => [],
            'raw' => [],
        ]);

        $this->service->capturePayment($order, 1000.0);

        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    // ------------------------------------------------------------------
    // Reflection helpers — FiscalizeSaleJob хранит orderId/paymentType/amount
    // в private промоутед-свойствах без публичных геттеров.
    // ------------------------------------------------------------------

    private function jobOrderId(FiscalizeSaleJob $job): int
    {
        return $this->readPrivateProperty($job, 'orderId');
    }

    private function jobPaymentType(FiscalizeSaleJob $job): string
    {
        return $this->readPrivateProperty($job, 'paymentType');
    }

    private function jobAmount(FiscalizeSaleJob $job): float
    {
        return $this->readPrivateProperty($job, 'amount');
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
