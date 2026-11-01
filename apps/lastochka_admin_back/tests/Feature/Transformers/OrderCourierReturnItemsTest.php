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
 * Возврат товара курьером при доставке (tg-14265) в order.* событии admin_back.
 *
 * delivery_back отдаёт по позиции доставленное (собрано − возвращено) и
 * returned_quantity, а admin_back слал полный собранный состав без возврата.
 * Прод-заказ 363/807 (2026-09-22): клиент вернул курьеру лук и картофель,
 * после ручной отметки оплаты событие вернуло их в заказ витрины, total
 * 250.54 → 420.44, returned_quantity → 0.
 */
class OrderCourierReturnItemsTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOrder(): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'courier-return-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => Status::ORDER_FINISHED,
            'payment_type_id' => 2,
            'type_id' => 2,
            'amount' => 250.54,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ]));
    }

    private function makeProduct(?int $infoWeight, int $infoWeightTypeId): int
    {
        return \DB::table('products')->insertGetId([
            'title' => 'Return product ' . uniqid(),
            'articul' => 'R-' . uniqid(),
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
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function events(Order $order): array
    {
        return app(OrderEventTransformer::class)->toEvent(
            $order->fresh()->load(['orderItems.product', 'orderItems.originProduct'])
        )['items'];
    }

    private function eventItem(Order $order, int $itemId): array
    {
        $item = collect($this->events($order))->firstWhere('id', (string) $itemId);
        $this->assertNotNull($item, 'позиция не попала в событие');

        return $item;
    }

    /** @test */
    public function fully_returned_weighted_item_is_sent_with_zero_quantity_total_and_weight()
    {
        $order = $this->makeOrder();
        // «Лук Красный» прод-заказа 807: 1 порция по 500 г, взвешено 500 г, вернули целиком
        $productId = $this->makeProduct(500, Type::INFO_WEIGHT_GRAM);
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 1,
            'price' => 42,
            'price_during_order' => 42,
            'actual_weight' => 500,
            'returned_quantity' => 1,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(0, $item['quantity']);
        $this->assertSame(0.0, $item['line_total']);
        $this->assertSame(0.0, $item['line_subtotal']);
        $this->assertSame(0, $item['actual_weight']);
        $this->assertSame(1, $item['returned_quantity']);
        $this->assertSame('confirmed', $item['item_status']);
    }

    /** @test */
    public function fully_returned_piece_item_is_sent_with_zero_quantity_and_total()
    {
        $order = $this->makeOrder();
        // «Картофель Фри» прод-заказа 807: 1 шт за 127.90, вернули
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 1,
            'price' => 127.9,
            'price_during_order' => 127.9,
            'returned_quantity' => 1,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(0, $item['quantity']);
        $this->assertSame(0.0, $item['line_total']);
        $this->assertSame(1, $item['returned_quantity']);
    }

    /** @test */
    public function partially_returned_piece_item_is_sent_with_delivered_quantity_and_total()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        // Собрано 10 шт по 42 (price после AssemblyFinish = итог строки), вернули 6
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 10,
            'price' => 420,
            'price_during_order' => 42,
            'returned_quantity' => 6,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(4, $item['quantity']);
        $this->assertSame(168.0, $item['line_total']);
        $this->assertSame(6, $item['returned_quantity']);
    }

    /** @test */
    public function item_without_return_is_sent_as_collected_with_zero_returned_quantity()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(200, Type::INFO_WEIGHT_GRAM);
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 3,
            'price' => 61.54,
            'price_during_order' => 58.5,
            'actual_weight' => 526,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(3, $item['quantity']);
        $this->assertSame(61.54, $item['line_total']);
        $this->assertSame(526, $item['actual_weight']);
        $this->assertSame(0, $item['returned_quantity']);
    }

    /** @test */
    public function partially_returned_piece_replacement_is_sent_with_delivered_quantity_and_total()
    {
        $order = $this->makeOrder();
        $originProductId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        // Замена 2 шт по 112.6 (price после AssemblyFinish = итог строки), вернули 1
        $itemId = $this->addItem($order->id, $productId, [
            'origin_product_id' => $originProductId,
            'origin_price' => 100,
            'origin_quantity' => 2,
            'quantity' => 2,
            'price' => 225.2,
            'price_during_order' => 112.6,
            'returned_quantity' => 1,
        ]);

        $replacement = collect($this->events($order))->firstWhere('id', (string) $itemId);

        $this->assertSame('added', $replacement['item_status']);
        $this->assertSame(1, $replacement['quantity']);
        $this->assertSame(112.6, $replacement['line_total']);
        $this->assertSame(1, $replacement['returned_quantity']);
    }

    /** @test */
    public function fully_returned_weighted_replacement_is_sent_with_zero_quantity_total_and_weight()
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
            'returned_quantity' => 3,
        ]);

        $replacement = collect($this->events($order))->firstWhere('id', (string) $itemId);

        $this->assertSame('added', $replacement['item_status']);
        $this->assertSame(0, $replacement['quantity']);
        $this->assertSame(0.0, $replacement['line_total']);
        $this->assertSame(0, $replacement['actual_weight']);
        $this->assertSame(3, $replacement['returned_quantity']);
    }
}
