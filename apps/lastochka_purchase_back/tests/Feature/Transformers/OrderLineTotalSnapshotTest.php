<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * line_total позиции в order.* событии purchase_back = снапшот order_items.price
 * (после AssemblyFinish это итог строки: по весу для весовых, unit × qty для
 * штучных), а НЕ пересчёт по текущему product.info_weight и НЕ price × quantity.
 *
 * Прод-заказ 180/354 (2026-08-25): смена статуса через purchase_back
 * перезаписала витрине лук 67.55 → 68 (round без копеек) и голубику
 * 385.92 → 154 (карточку товара поменяли после сборки, шаг 200 → 500 г).
 */
class OrderLineTotalSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private const INFO_WEIGHT_PIECE = 1;
    private const INFO_WEIGHT_GRAM = 2;
    private const INFO_WEIGHT_KILOGRAM = 3;

    private function transformer(): OrderEventTransformer
    {
        return app(OrderEventTransformer::class);
    }

    private function makeOrder(): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        $order = new Order();
        $order->forceFill([
            'uuid' => 'line-total-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => 1,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ])->save();

        return $order;
    }

    private function makeProduct(?int $infoWeight, int $infoWeightTypeId): int
    {
        return \DB::table('products')->insertGetId([
            'title' => 'Snapshot product ' . uniqid(),
            'articul' => 'S-' . uniqid(),
            'unit_type_id' => 1,
            'info_weight' => $infoWeight,
            'info_weight_type_id' => $infoWeightTypeId,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addItem(int $orderId, int $productId, array $attrs): int
    {
        return \DB::table('order_items')->insertGetId(array_merge([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => 1,
            'price' => 0,
            'price_during_order' => 0,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function changeStep(int $productId, int $infoWeight): void
    {
        \DB::table('products')->where('id', $productId)->update(['info_weight' => $infoWeight]);
    }

    private function eventItem(Order $order, int $itemId): array
    {
        $event = $this->transformer()->toEvent($order->fresh()->load('orderItems.product'));

        $item = collect($event['items'])->firstWhere('id', (string) $itemId);
        $this->assertNotNull($item, 'позиция не попала в событие');

        return $item;
    }

    /** @test */
    public function weighted_line_total_is_snapshot_not_live_product_step()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(200, self::INFO_WEIGHT_GRAM);
        // AssemblyFinish: 3 порции по 200 г, взвешено 536 г → 144 × 536 / 200 = 385.92
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 3,
            'price' => 385.92,
            'price_during_order' => 144,
            'actual_weight' => 536,
        ]);

        // Карточку товара поменяли уже после сборки: шаг 200 → 500 г
        $this->changeStep($productId, 500);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(385.92, $item['line_total']);
        $this->assertSame(385.92, $item['line_subtotal']);
        $this->assertSame(144.0, $item['unit_price']);
        $this->assertSame(536, $item['actual_weight']);
    }

    /** @test */
    public function weighted_line_total_keeps_kopecks()
    {
        $order = $this->makeOrder();
        // Лук: кг без кванта, 35 ₽/кг, взвешено 1.93 кг → 67.55
        $productId = $this->makeProduct(null, self::INFO_WEIGHT_KILOGRAM);
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 2,
            'price' => 67.55,
            'price_during_order' => 35,
            'actual_weight' => 1930,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(67.55, $item['line_total']);
        $this->assertSame(35.0, $item['unit_price']);
    }

    /** @test */
    public function piece_line_total_after_finish_is_unit_price_times_quantity()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(null, self::INFO_WEIGHT_PIECE);
        // После AssemblyFinish order_items.price = итог строки (112.6 × 2),
        // повторное домножение на quantity удваивало сумму
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 2,
            'price' => 225.2,
            'price_during_order' => 112.6,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(225.2, $item['line_total']);
        $this->assertSame(112.6, $item['unit_price']);
        $this->assertArrayNotHasKey('actual_weight', $item);
    }

    /** @test */
    public function weighted_replacement_line_total_is_snapshot()
    {
        $order = $this->makeOrder();
        $originProductId = $this->makeProduct(200, self::INFO_WEIGHT_GRAM);
        $productId = $this->makeProduct(200, self::INFO_WEIGHT_GRAM);
        $itemId = $this->addItem($order->id, $productId, [
            'origin_product_id' => $originProductId,
            'origin_price' => 144,
            'origin_quantity' => 3,
            'quantity' => 3,
            'price' => 385.92,
            'price_during_order' => 144,
            'actual_weight' => 536,
        ]);

        $this->changeStep($productId, 500);

        $event = $this->transformer()->toEvent(
            $order->fresh()->load(['orderItems.product', 'orderItems.originProduct'])
        );
        $replacement = collect($event['items'])->firstWhere('id', (string) $itemId);
        $origin = collect($event['items'])->firstWhere('id', $itemId . '_origin');

        $this->assertSame('added', $replacement['item_status']);
        $this->assertSame(385.92, $replacement['line_total']);
        $this->assertSame(536, $replacement['actual_weight']);
        $this->assertSame('replaced', $origin['item_status']);
        $this->assertSame(0, $origin['line_total']);
    }

    /** @test */
    public function piece_replacement_line_total_is_unit_price_times_quantity()
    {
        $order = $this->makeOrder();
        $originProductId = $this->makeProduct(null, self::INFO_WEIGHT_PIECE);
        $productId = $this->makeProduct(null, self::INFO_WEIGHT_PIECE);
        $itemId = $this->addItem($order->id, $productId, [
            'origin_product_id' => $originProductId,
            'origin_price' => 100,
            'origin_quantity' => 2,
            'quantity' => 2,
            'price' => 225.2,
            'price_during_order' => 112.6,
        ]);

        $event = $this->transformer()->toEvent(
            $order->fresh()->load(['orderItems.product', 'orderItems.originProduct'])
        );
        $replacement = collect($event['items'])->firstWhere('id', (string) $itemId);

        $this->assertSame('added', $replacement['item_status']);
        $this->assertSame(225.2, $replacement['line_total']);
    }
}
