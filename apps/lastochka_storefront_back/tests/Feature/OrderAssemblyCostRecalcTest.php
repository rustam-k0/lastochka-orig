<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Локальные пересчёты orders.total не теряют стоимость сборки (assembly_cost).
 *
 * Родственный класс бага «assembly_cost lost on recalc»: холд в YooKassa
 * берётся на total со сборкой, а пересчёты total после синка позиций из
 * delivery_back и после дополнения заказа собирали сумму без assembly_cost —
 * от этого расходились captured/refund в финализации и клиент видел
 * заниженный total.
 */
final class OrderAssemblyCostRecalcTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_sync_recalc_keeps_assembly_cost_in_total(): void
    {
        $store = Store::factory()->create(['free_delivery_from' => null]);
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembling,
            'payment_type' => 'cash',
            'subtotal' => 1845,
            'discount' => 0,
            'delivery_cost' => 0,
            'assembly_cost' => 150,
            'total' => 1995,
        ]);

        $order->items()->create([
            'product_id' => null,
            'title' => 'Кофе в зёрнах',
            'sku' => 'AC-SKU',
            'quantity' => 1,
            'price' => 1845,
            'total' => 1845,
            'eligible_for_bonus' => true,
            'status' => null,
        ]);

        app(OrderEventTransformer::class)->afterUpdate($order->fresh(), [
            'status' => 'assembling',
            'grand_total' => 1995.0,
            'items' => [[
                'id' => 'ext-ac-1',
                'source' => 'delivery',
                'product_name' => 'Кофе в зёрнах',
                'item_status' => 'confirmed',
                'quantity' => 1,
                'unit_price' => 1845,
                'unit_price_effective' => 1845,
            ]],
        ]);

        $this->assertSame(1995.0, (float) $order->fresh()->total);
    }

    public function test_supplement_recalc_keeps_assembly_cost_in_total(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['free_delivery_from' => null]);

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
            'assembly_cost' => 150,
            'total' => 450,
            'items_count' => 1,
            'shipping_method' => ShippingMethod::Courier,
        ]);

        OrderItem::factory()->for($order)->create([
            'quantity' => 1,
            'price' => 300,
            'total' => 300,
        ]);

        $product = Product::factory()->for($store)->create([
            'sku' => 'AC-SUPPLEMENT',
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        app(SupplementOrderAction::class)->execute($order, $user);

        // items 300 + 500 + сборка 150 (доставка 0)
        $this->assertSame(950.0, (float) $order->fresh()->total);
    }
}
