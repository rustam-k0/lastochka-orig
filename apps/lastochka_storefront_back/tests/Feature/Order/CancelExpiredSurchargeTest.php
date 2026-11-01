<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

final class CancelExpiredSurchargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
    }

    private function surchargeOrder(\DateTimeInterface $expiresAt): Order
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        return Order::create([
            'user_id' => $user->id, 'store_id' => $store->id,
            'status' => OrderStatus::Assembling, 'payment_type' => PaymentType::Card,
            'subtotal' => 6000, 'total' => 6000, 'held_amount' => 5000,
            'items_count' => 1, 'shipping_method' => ShippingMethod::Courier,
            'surcharge_amount' => 1000, 'surcharge_expires_at' => $expiresAt,
        ]);
    }

    public function test_cancels_expired_surcharge_order(): void
    {
        $expired = $this->surchargeOrder(now()->subMinutes(5));
        $active = $this->surchargeOrder(now()->addHours(2));

        $this->artisan('orders:cancel-expired-surcharge')->assertExitCode(0);

        $this->assertSame(OrderStatus::Cancelled, $expired->fresh()->status);
        $this->assertSame(OrderStatus::Assembling, $active->fresh()->status);
    }

    /**
     * Страховка кейса «доплата списалась, capture так и не прошёл»:
     * surcharge_amount NULL, но дедлайн стоит — заказ тоже отменяется
     * (возврат доплаты + снятие холда делает refundOrReleaseHold внутри
     * CancelOrderAction).
     */
    public function test_cancels_expired_capture_retry_order_without_amount(): void
    {
        $order = $this->surchargeOrder(now()->subMinutes(5));
        $order->update(['surcharge_amount' => null]);

        $this->artisan('orders:cancel-expired-surcharge')->assertExitCode(0);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }
}
