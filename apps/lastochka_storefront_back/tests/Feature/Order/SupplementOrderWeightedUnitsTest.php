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
use Tests\TestCase;

/**
 * Дозаказ весового товара «по кг» без кванта: позиция заказа хранит граммы
 * (1000 gram), корзина — килограммы (1). Без перевода слияние давало 1001 «г»
 * и total 35 035 ₽, а новая позиция ложилась как (1, без единицы) — вес 1 г.
 */
final class SupplementOrderWeightedUnitsTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $user, Store $store): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'source' => 'storefront',
            'status' => OrderStatus::Assembling,
            'payment_type' => 'cash',
            'is_paid' => false,
            'subtotal' => 35,
            'discount' => 0,
            'eligible_amount' => 0,
            'delivery_cost' => 0,
            'total' => 35,
            'items_count' => 1,
            'shipping_method' => ShippingMethod::Courier,
        ]);
    }

    private function kilogramProduct(Store $store): Product
    {
        return Product::factory()->for($store)->create([
            'price' => 35, 'quantity' => 100000, 'quantity_step' => 1,
            'measurement_unit' => 'kilogram', 'unit_type' => 'kg',
        ]);
    }

    private function putInCart(User $user, Store $store, Product $product, int $quantity): void
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $quantity,
            'price' => $product->price,
            'price_old' => null,
        ]);
    }

    public function test_merge_adds_kilograms_as_grams(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = $this->kilogramProduct($store);
        $order = $this->order($user, $store);
        $existing = OrderItem::factory()->for($order)->for($product)->create([
            'price' => 35, 'quantity' => 1000, 'total' => 35, 'measurement_unit' => 'gram',
        ]);
        $this->putInCart($user, $store, $product, 1);

        app(SupplementOrderAction::class)->execute($order, $user);

        $existing->refresh();
        $this->assertSame(2000, (int) $existing->quantity);
        $this->assertSame(70.0, (float) $existing->total);
    }

    public function test_new_kilogram_item_is_stored_in_grams(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = $this->order($user, $store);
        $product = $this->kilogramProduct($store);
        $this->putInCart($user, $store, $product, 2);

        app(SupplementOrderAction::class)->execute($order, $user);

        $item = $order->items()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(2000, (int) $item->quantity);
        $this->assertSame('gram', $item->measurement_unit?->value);
        $this->assertSame(70.0, (float) $item->total);
    }

    public function test_new_portioned_item_keeps_grams(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = $this->order($user, $store);
        $product = Product::factory()->for($store)->create([
            'price' => 178.20, 'quantity' => 100000, 'quantity_step' => 300,
            'measurement_unit' => 'gram', 'unit_type' => 'kg',
        ]);
        $this->putInCart($user, $store, $product, 900);

        app(SupplementOrderAction::class)->execute($order, $user);

        $item = $order->items()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(900, (int) $item->quantity);
        $this->assertSame('gram', $item->measurement_unit?->value);
        $this->assertSame(534.6, (float) $item->total);
    }
}
