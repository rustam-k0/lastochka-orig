<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Тесты для PaymentService::refundOrReleaseHold — единая точка возврата денег
 * при отмене заказа: списанные деньги (captured) возвращаются рефандом,
 * не списанные (held) — снятием холда.
 */
final class PaymentServiceRefundOrReleaseHoldTest extends TestCase
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

    private function cardOrder(array $attributes): Order
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        return Order::factory()->for($user)->for($store)->create(array_merge([
            'payment_type' => PaymentType::Card,
            'total' => 500,
        ], $attributes));
    }

    public function test_refunds_full_captured_amount_when_nothing_refunded_yet(): void
    {
        $order = $this->cardOrder(['captured_amount' => 500, 'refunded_amount' => 0]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_captured_1',
        ]);

        $result = $this->service->refundOrReleaseHold($order);

        $this->assertTrue($result->success);

        $refundCalls = $this->gateway->callsFor('refund');
        $this->assertCount(1, $refundCalls);
        $this->assertSame(500.0, $refundCalls[0]['args']['amount']);
        $this->assertCount(0, $this->gateway->callsFor('cancel'), 'cancelHold НЕ должен вызываться при captured');

        $refundTx = PaymentTransaction::where('order_id', $order->id)
            ->where('type', PaymentTransactionType::Refund)
            ->first();
        $this->assertNotNull($refundTx);
        $this->assertSame(500.0, (float) $refundTx->amount);
    }

    public function test_refunds_only_remainder_when_partially_refunded(): void
    {
        $order = $this->cardOrder(['captured_amount' => 500, 'refunded_amount' => 200]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_captured_2',
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Refund,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 200,
            'yookassa_payment_id' => 'pay_captured_2',
            'yookassa_refund_id' => 'rf_existing',
        ]);

        $result = $this->service->refundOrReleaseHold($order);

        $this->assertTrue($result->success);

        $refundCalls = $this->gateway->callsFor('refund');
        $this->assertCount(1, $refundCalls);
        $this->assertSame(300.0, $refundCalls[0]['args']['amount'], 'возврат только остатка captured - refunded');
    }

    public function test_cancels_hold_when_not_captured(): void
    {
        $order = $this->cardOrder(['captured_amount' => 0, 'held_amount' => 400]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 400,
            'yookassa_payment_id' => 'pay_hold_1',
        ]);

        $result = $this->service->refundOrReleaseHold($order);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->gateway->callsFor('cancel'));
        $this->assertCount(0, $this->gateway->callsFor('refund'), 'refund НЕ должен вызываться без captured');

        $holdTx = PaymentTransaction::where('order_id', $order->id)
            ->where('type', PaymentTransactionType::Hold)
            ->first();
        $this->assertSame(PaymentTransactionStatus::Canceled, $holdTx->status);
    }

    public function test_noop_when_no_captured_and_no_held(): void
    {
        $order = $this->cardOrder(['captured_amount' => 0, 'held_amount' => 0]);

        $result = $this->service->refundOrReleaseHold($order);

        $this->assertTrue($result->success);
        $this->assertCount(0, $this->gateway->callsFor('refund'));
        $this->assertCount(0, $this->gateway->callsFor('cancel'));
    }

    public function test_noop_for_non_card_payment(): void
    {
        $order = $this->cardOrder([
            'payment_type' => PaymentType::Cash,
            'captured_amount' => 500,
            'held_amount' => 500,
        ]);

        $result = $this->service->refundOrReleaseHold($order);

        $this->assertTrue($result->success);
        $this->assertCount(0, $this->gateway->callsFor('refund'));
        $this->assertCount(0, $this->gateway->callsFor('cancel'));
    }
}
