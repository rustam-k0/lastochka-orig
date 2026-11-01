<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * line_total весовой позиции в order.* событии — снапшот, зафиксированный при
 * сборке (order_items.price после AssemblyFinish, assembly_items.price до него),
 * а НЕ пересчёт по текущему product.info_weight.
 *
 * Прод-заказ 180/354 (2026-08-25): карточку «Голубика Вес» поменяли (шаг
 * 200 → 500 г) между сборкой и доставкой, событие статуса пересчитало строку
 * 385.92 → 154.37, витрина и чек разошлись с курьеркой на 231 ₽.
 */
class OrderLineTotalSnapshotTest extends TestCase
{
    use DatabaseTransactions;

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
            'status_id' => Status::ORDER_ASSEMBLED,
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

    private function eventItem(Order $order, int $itemId, array $relations = ['orderItems.product']): array
    {
        $event = $this->transformer()->toEvent($order->fresh()->load($relations));

        $item = collect($event['items'])->firstWhere('id', (string) $itemId);
        $this->assertNotNull($item, 'позиция не попала в событие');

        return $item;
    }

    /** @test */
    public function weighted_line_total_after_finish_is_snapshot_not_live_product_step()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(200, Type::INFO_WEIGHT_GRAM);
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
    public function weighted_line_total_before_finish_is_assembly_item_snapshot()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(200, Type::INFO_WEIGHT_GRAM);
        // Сборка идёт: order_item ещё в порциях и с ценой за порцию,
        // вес и итог строки — в assembly_items (ProductConfirm)
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 3,
            'price' => 144,
            'price_during_order' => 144,
            'actual_weight' => null,
        ]);
        $userId = \DB::table('users')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $order->id, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('assembly_items')->insert([
            'assembly_id' => $assemblyId,
            'order_item_id' => $itemId,
            'quantity' => null,
            'actual_weight' => 536,
            'price' => 385.92,
            'status_id' => Status::PRODUCT_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->changeStep($productId, 500);

        $item = $this->eventItem($order, $itemId, ['orderItems.product', 'orderItems.assemblyItem']);

        $this->assertSame(385.92, $item['line_total']);
        $this->assertSame(536, $item['actual_weight']);
    }

    /** @test */
    public function weighted_replacement_line_total_after_finish_is_snapshot()
    {
        $order = $this->makeOrder();
        $originProductId = $this->makeProduct(200, Type::INFO_WEIGHT_GRAM);
        $productId = $this->makeProduct(200, Type::INFO_WEIGHT_GRAM);
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
    public function weighted_fully_returned_line_total_is_zero()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(200, Type::INFO_WEIGHT_GRAM);
        // Весовой возвращается только полностью: returned = collected (536 / 200 → 3)
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 3,
            'price' => 385.92,
            'price_during_order' => 144,
            'actual_weight' => 536,
            'returned_quantity' => 3,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(0.0, $item['line_total']);
        $this->assertSame(0, $item['actual_weight']);
        $this->assertSame(0, $item['quantity']);
    }
}
