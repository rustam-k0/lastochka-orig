<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Товар заказа должен резолвиться по external_id (GUID 1С), а не через Product::find().
 * Регрессия: product_id в событии — GUID "7560c574-..."; find() приводил его к int
 * по числовому префиксу (7560) и находил чужой товар (икру вместо чипсов),
 * чей external_id уходил в 1С.
 */
class OrderItemProductLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_product_by_external_id_not_numeric_cast(): void
    {
        // Чужой товар с числовым id, равным префиксу GUID чипсов
        $ikra = new Product([
            'external_id' => '12c928f5-66ef-11eb-b056-001517b3ea47',
            'name' => 'Икра Лососевая',
            'shop_id' => 3,
        ]);
        $ikra->id = 7560;
        $ikra->save();

        // Настоящий товар заказа
        $chips = Product::create([
            'external_id' => '7560c574-db85-11ed-b9a3-001517b3ea47',
            'name' => 'Чипсы Лейс Сметана и зелень 70гр.',
            'shop_id' => 3,
        ]);

        $order = Order::create([
            'shop_order_id' => 304, 'number' => '304', 'status' => Order::STATUS_ASSEMBLED,
            'source' => Order::SOURCE_SHOP, 'shop_id' => 3, 'customer_name' => 'Покупатель',
            'customer_phone' => '+79990000000', 'total' => 160, 'received_at' => now(),
        ]);

        (new OrderEventTransformer())->afterCreate($order, ['payload' => ['items' => [[
            'product_id' => '7560c574-db85-11ed-b9a3-001517b3ea47',
            'product_source' => '1c',
            'product_name' => 'Чипсы Лейс Сметана и зелень 70гр.',
            'item_status' => 'confirmed',
            'unit_price' => 80, 'quantity' => 2, 'line_total' => 160,
        ]]]]);

        $item = $order->items()->firstOrFail();

        // В 1С должен уйти external_id чипсов, а не икры
        $this->assertSame('7560c574-db85-11ed-b9a3-001517b3ea47', $item->external_id);
        $this->assertSame($chips->id, $item->product_id);
        $this->assertNotSame(7560, (int) $item->product_id);
    }
}
