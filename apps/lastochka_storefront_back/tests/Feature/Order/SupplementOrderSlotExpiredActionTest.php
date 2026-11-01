<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\SupplementOrderAction;
use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Exceptions\OrderSupplementSlotExpiredException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ALD-130: повторная проверка слота доставки внутри транзакции SupplementOrderAction —
 * подстраховка на случай гонки между авторизацией (OrderPolicy::supplement, 403) и
 * захватом блокировки заказа. Основной путь клиент получает через 403 у контроллера
 * (см. OrderSupplementTest), здесь — сам Action и формат 409-ответа исключения.
 */
final class SupplementOrderSlotExpiredActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(User $user, Store $store, ?\DateTimeInterface $slotStart, ?\DateTimeInterface $slotEnd): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'source' => 'storefront',
            'status' => OrderStatus::Assembling,
            'payment_type' => 'cash',
            'is_paid' => false,
            'subtotal' => 300,
            'discount' => 0,
            'eligible_amount' => 0,
            'delivery_cost' => 0,
            'total' => 300,
            'items_count' => 1,
            'delivery_slot_start' => $slotStart,
            'delivery_slot_end' => $slotEnd,
            'shipping_method' => ShippingMethod::Courier,
        ]);

        OrderItem::factory()->for($order)->create();

        return $order;
    }

    public function test_action_throws_slot_expired_exception_when_slot_passed(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = $this->makeOrder($user, $store, now()->subHours(7), now()->subHours(6));

        $product = Product::factory()->for($store)->create([
            'sku' => 'SLOT-EXPIRED',
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $this->expectException(OrderSupplementSlotExpiredException::class);

        try {
            app(SupplementOrderAction::class)->execute($order, $user);
        } catch (OrderSupplementSlotExpiredException $e) {
            $response = $e->render();
            $data = $response->getData(true);

            $this->assertSame(409, $response->getStatusCode());
            $this->assertArrayHasKey('deliverySlot', $data['errors']);

            // Товар не резервируется, заказ не изменяется
            $this->assertSame(1, $order->fresh()->items()->count());
            $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 10]);

            throw $e;
        }
    }

    public function test_action_succeeds_when_order_has_no_delivery_slot(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = $this->makeOrder($user, $store, null, null);

        $product = Product::factory()->for($store)->create([
            'sku' => 'SLOT-NULL',
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $result = app(SupplementOrderAction::class)->execute($order, $user);

        $this->assertNotNull($result);
    }
}
