<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Стоимость доставки после сборки берётся из события курьерки (shipping_total).
 *
 * Порог бесплатной доставки живёт в зоне (shop_zones.free_delivery_from в
 * курьерке), витрина знает только общий порог магазина. Прод-заказ 213
 * (11.09.2026): зона «Поселок Нартан» — доставка 250 ₽, бесплатно от 5000 ₽,
 * магазин — от 2500 ₽. Витрина обнуляла доставку на сумме 2559 ₽, и клиент
 * видел итог на 250 ₽ меньше суммы, списанной по расчёту курьерки.
 */
final class OrderDeliveryCostFromAssemblyEventTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(Store $store, float $deliveryCost): Order
    {
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembling,
            'payment_type' => 'card',
            'subtotal' => 2800,
            'discount' => 0,
            'delivery_cost' => $deliveryCost,
            'assembly_cost' => 0,
            'total' => 2800 + $deliveryCost,
        ]);

        $order->items()->create([
            'product_id' => null,
            'title' => 'Арбузы Дагестанские',
            'sku' => 'DC-SKU',
            'quantity' => 1,
            'price' => 2800,
            'total' => 2800,
            'eligible_for_bonus' => true,
            'status' => null,
        ]);

        return $order->fresh();
    }

    private function assemblyEvent(float $subtotal, ?float $shippingTotal): array
    {
        $event = [
            'status' => 'assembling',
            'items' => [[
                'id' => 'ext-dc-1',
                'source' => 'delivery',
                'product_name' => 'Арбузы Дагестанские',
                'item_status' => 'confirmed',
                'quantity' => 1,
                'unit_price' => $subtotal,
                'unit_price_effective' => $subtotal,
                'line_total' => $subtotal,
            ]],
        ];

        if ($shippingTotal !== null) {
            $event['shipping_total'] = $shippingTotal;
        }

        return $event;
    }

    public function test_delivery_cost_comes_from_event_even_above_store_threshold(): void
    {
        $store = Store::factory()->create(['free_delivery_from' => 2500]);
        $order = $this->makeOrder($store, 250);

        app(OrderEventTransformer::class)->afterUpdate($order, $this->assemblyEvent(2559.37, 250.0));

        $fresh = $order->fresh();

        $this->assertSame(250.0, (float) $fresh->delivery_cost,
            'порог зоны не пройден — доставка курьерки сохранена');
        $this->assertSame(2809.37, (float) $fresh->total, 'итог клиента совпадает с расчётом курьерки');
    }

    public function test_delivery_cost_zeroed_when_courier_made_it_free(): void
    {
        $store = Store::factory()->create(['free_delivery_from' => 2500]);
        $order = $this->makeOrder($store, 250);

        app(OrderEventTransformer::class)->afterUpdate($order, $this->assemblyEvent(2559.37, 0.0));

        $this->assertSame(0.0, (float) $order->fresh()->delivery_cost,
            'курьерка сделала доставку бесплатной — витрина следует за ней');
    }

    public function test_falls_back_to_store_threshold_without_shipping_total(): void
    {
        $store = Store::factory()->create(['free_delivery_from' => 2500]);
        $order = $this->makeOrder($store, 250);

        app(OrderEventTransformer::class)->afterUpdate($order, $this->assemblyEvent(2559.37, null));

        $this->assertSame(0.0, (float) $order->fresh()->delivery_cost,
            'без shipping_total работает прежний пересчёт по порогу магазина');
    }
}
