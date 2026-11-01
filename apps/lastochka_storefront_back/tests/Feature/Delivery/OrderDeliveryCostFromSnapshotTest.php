<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MID-280: событие сборки без shipping_total пересчитывает доставку по снимку
 * условий доставки заказа: ступень по сумме после сборки, не дороже оформления.
 * shipping_total из события курьерки по-прежнему важнее (OrderDeliveryCostFromAssemblyEventTest).
 */
final class OrderDeliveryCostFromSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(?array $snapshot, float $deliveryCost, ?int $storeFreeDeliveryFrom): Order
    {
        $store = Store::factory()->create(['free_delivery_from' => $storeFreeDeliveryFrom]);

        $order = Order::factory()->for(User::factory())->for($store)->create([
            'status' => OrderStatus::Assembling,
            'payment_type' => 'card',
            'subtotal' => 700,
            'discount' => 0,
            'delivery_cost' => $deliveryCost,
            'delivery_price_snapshot' => $snapshot,
            'assembly_cost' => 0,
            'total' => 700 + $deliveryCost,
        ]);

        $order->items()->create([
            'product_id' => null,
            'title' => 'Кофе',
            'sku' => 'SNAP-SKU',
            'quantity' => 1,
            'price' => 700,
            'total' => 700,
            'eligible_for_bonus' => true,
            'status' => null,
        ]);

        return $order->fresh();
    }

    private function assemblyEvent(float $subtotal): array
    {
        return [
            'status' => 'assembling',
            'items' => [[
                'id' => 'ext-snap-1',
                'source' => 'delivery',
                'product_name' => 'Кофе',
                'item_status' => 'confirmed',
                'quantity' => 1,
                'unit_price' => $subtotal,
                'unit_price_effective' => $subtotal,
                'line_total' => $subtotal,
            ]],
        ];
    }

    private function snapshot(int $checkoutPrice): array
    {
        return [
            'base_price' => 200,
            'ranges' => [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ],
            'checkout_price' => $checkoutPrice,
        ];
    }

    public function test_amount_grew_into_cheaper_range(): void
    {
        // Оформлен на 700 (доставка 200), после сборки 1500: ступень 100.
        // Прежний пересчёт без порога магазина оставлял 200.
        $order = $this->makeOrder($this->snapshot(200), 200, null);

        app(OrderEventTransformer::class)->afterUpdate($order, $this->assemblyEvent(1500));

        $fresh = $order->fresh();
        $this->assertSame(100.0, (float) $fresh->delivery_cost);
        $this->assertSame(1600.0, (float) $fresh->total);
    }

    public function test_snapshot_wins_over_store_threshold(): void
    {
        // Порог магазина 1000 обнулил бы доставку, по ступеням зоны на 1200 она 100
        $order = $this->makeOrder($this->snapshot(200), 200, 1000);

        app(OrderEventTransformer::class)->afterUpdate($order, $this->assemblyEvent(1200));

        $this->assertSame(100.0, (float) $order->fresh()->delivery_cost);
    }

    public function test_amount_dropped_is_capped_by_checkout_price(): void
    {
        // Оформлен со ступенью 100, после сборки 900: по лесенке 200, но не дороже оформления.
        // Порог магазина 500 обнулил бы доставку.
        $order = $this->makeOrder($this->snapshot(100), 100, 500);

        app(OrderEventTransformer::class)->afterUpdate($order, $this->assemblyEvent(900));

        $fresh = $order->fresh();
        $this->assertSame(100.0, (float) $fresh->delivery_cost);
        $this->assertSame(1000.0, (float) $fresh->total);
    }

    public function test_shipping_total_still_wins_over_snapshot(): void
    {
        $order = $this->makeOrder($this->snapshot(200), 200, null);

        $event = $this->assemblyEvent(1500);
        $event['shipping_total'] = 200.0;

        app(OrderEventTransformer::class)->afterUpdate($order, $event);

        $this->assertSame(200.0, (float) $order->fresh()->delivery_cost);
    }

    public function test_order_without_snapshot_keeps_store_threshold_fallback(): void
    {
        $order = $this->makeOrder(null, 200, 1000);

        app(OrderEventTransformer::class)->afterUpdate($order, $this->assemblyEvent(1200));

        $this->assertSame(0.0, (float) $order->fresh()->delivery_cost);
    }
}
