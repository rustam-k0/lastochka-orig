<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CancelOrderAction;
use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Возврат денег при клиентской отмене заказа (CancelOrderAction::cancelStandard).
 */
final class CancelOrderRefundTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function cardOrder(array $attributes): Order
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        return Order::factory()->for($user)->for($store)->create(array_merge([
            'status' => OrderStatus::Assembling,
            'payment_type' => PaymentType::Card,
            'total' => 500,
        ], $attributes));
    }

    public function test_cancelling_captured_order_refunds_money(): void
    {
        $order = $this->cardOrder(['captured_amount' => 500, 'held_amount' => 500]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_cap_cancel',
        ]);

        app(CancelOrderAction::class)->execute($order);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);

        $refundCalls = $this->gateway->callsFor('refund');
        $this->assertCount(1, $refundCalls);
        $this->assertSame(500.0, $refundCalls[0]['args']['amount']);

        $refundTx = PaymentTransaction::where('order_id', $order->id)
            ->where('type', PaymentTransactionType::Refund)
            ->first();
        $this->assertNotNull($refundTx);
    }

    public function test_cancelling_held_only_order_cancels_hold_without_refund(): void
    {
        $order = $this->cardOrder(['captured_amount' => 0, 'held_amount' => 500]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_hold_cancel',
        ]);

        app(CancelOrderAction::class)->execute($order);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertCount(1, $this->gateway->callsFor('cancel'));
        $this->assertCount(0, $this->gateway->callsFor('refund'));
    }
}
