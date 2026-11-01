<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\SupplementOrderAction;
use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SupplementOrderPickupOnlyRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplementableOrder(User $user, Store $store, ShippingMethod $shippingMethod): Order
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
            'shipping_method' => $shippingMethod,
        ]);

        // items_count=1 above описывает уже существующий товар в заказе — создаём
        // соответствующий OrderItem, иначе assertSame(1, $order->items()->count())
        // ниже не отражает реальное состояние заказа.
        OrderItem::factory()->for($order)->create();

        return $order;
    }

    public function test_supplementing_with_pickup_only_product_is_rejected_for_courier_order(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = $this->makeSupplementableOrder($user, $store, ShippingMethod::Courier);

        $product = Product::factory()->for($store)->create([
            'sku' => 'WINE-SUPPLEMENT',
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'pickup_only' => true,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(SupplementOrderAction::class)->execute($order, $user);
        } catch (ValidationException $e) {
            $data = $e->response->getData(true);
            $this->assertArrayHasKey('shippingMethod', $data['errors']);
            $this->assertSame(1, $order->fresh()->items()->count());

            throw $e;
        }
    }

    public function test_supplementing_with_pickup_only_product_succeeds_for_pickup_order(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = $this->makeSupplementableOrder($user, $store, ShippingMethod::Pickup);

        $product = Product::factory()->for($store)->create([
            'sku' => 'WINE-SUPPLEMENT-PICKUP',
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'pickup_only' => true,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        $result = app(SupplementOrderAction::class)->execute($order, $user);

        $this->assertNotNull($result);
    }

    public function test_supplementing_without_pickup_only_product_is_unaffected(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = $this->makeSupplementableOrder($user, $store, ShippingMethod::Courier);

        $product = Product::factory()->for($store)->create([
            'sku' => 'MILK-SUPPLEMENT',
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'pickup_only' => false,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 300,
        ]);

        $result = app(SupplementOrderAction::class)->execute($order, $user);

        $this->assertNotNull($result);
    }
}
