<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\AutoCancelPickupOrderAction;
use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Защитный тест: авто-отмена pickup-заказа по таймауту возвращает списанные
 * деньги (capture происходит при Assembled). Фиксирует поведение перед рефактором
 * на общий PaymentService::refundOrReleaseHold.
 */
final class AutoCancelPickupRefundTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_auto_cancel_refunds_captured_pickup_order(): void
    {
        $store = Store::factory()->create(['code' => 'PKP_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembled,
            'shipping_method' => ShippingMethod::Pickup,
            'payment_type' => PaymentType::Card,
            'total' => 700,
            'captured_amount' => 700,
            'held_amount' => 700,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 700,
            'yookassa_payment_id' => 'pay_pickup_cap',
        ]);

        app(AutoCancelPickupOrderAction::class)->execute($order);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);

        $refundCalls = $this->gateway->callsFor('refund');
        $this->assertCount(1, $refundCalls);
        $this->assertSame(700.0, $refundCalls[0]['args']['amount']);
    }
}
