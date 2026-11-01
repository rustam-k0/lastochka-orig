<?php

namespace Tests\Feature;

use App\Models\Order;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Exchange1C\OrderExportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * В 1С выгружаются только реально собранные позиции (confirmed/added),
 * а стоимость доставки уходит отдельной позицией. Регрессия по заказу 299:
 * замена товара (replaced-оригинал) ошибочно попадала в выгрузку, а доставка терялась.
 */
class OrderAssembledItemsAndDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'shop_order_id' => 299,
            'number' => '202606160001',
            'status' => Order::STATUS_ASSEMBLED,
            'source' => Order::SOURCE_SHOP,
            'shop_id' => 3,
            'customer_name' => 'Покупатель',
            'customer_phone' => '+79882200871',
            'total' => 208,
            'delivery_cost' => 100,
            'received_at' => now(),
        ], $attrs));
    }

    public function test_after_create_keeps_only_assembled_items(): void
    {
        $order = $this->makeOrder();

        $eventData = ['payload' => ['items' => [
            // Заменённый оригинал — не собран, не должен попасть в 1С
            ['product_id' => 'guid-kola', 'product_name' => 'Kinza кола', 'item_status' => 'replaced',
             'unit_price' => 74, 'quantity' => 2, 'line_total' => 0],
            // Товар-замена — реально собран
            ['product_id' => 'guid-orange', 'product_name' => 'Kinza апельсин', 'item_status' => 'added',
             'unit_price' => 54, 'quantity' => 2, 'line_total' => 108],
            // Отменённая позиция — не собрана
            ['product_id' => 'guid-x', 'product_name' => 'Нет в наличии', 'item_status' => 'out_of_stock',
             'unit_price' => 10, 'quantity' => 1, 'line_total' => 0],
        ]]];

        (new OrderEventTransformer())->afterCreate($order, $eventData);

        $this->assertSame(1, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'name' => 'Kinza апельсин', 'total' => 108]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'name' => 'Kinza кола']);
    }

    public function test_after_create_keeps_items_without_status(): void
    {
        // item_status отсутствует (старые события / прямые заказы) — позицию не фильтруем
        $order = $this->makeOrder(['shop_order_id' => 300]);

        $eventData = ['payload' => ['items' => [
            ['product_id' => 'guid-a', 'product_name' => 'Товар', 'unit_price' => 50, 'quantity' => 1, 'line_total' => 50],
        ]]];

        (new OrderEventTransformer())->afterCreate($order, $eventData);

        $this->assertSame(1, $order->items()->count());
    }

    public function test_from_event_captures_delivery_cost(): void
    {
        $data = (new OrderEventTransformer())->fromEvent([
            'payload' => [
                'id' => 299, 'status' => 'assembled', 'shop_code' => 'aladdinmarketplace',
                'grand_total' => 208, 'shipping_total' => 100,
                'client_info' => ['phone' => '+79882200871'],
            ],
        ]);

        $this->assertSame(100.0, $data['delivery_cost']);
        $this->assertSame(208.0, $data['total']);
    }

    public function test_export_adds_delivery_position(): void
    {
        $order = $this->makeOrder();
        $order->items()->create([
            'external_id' => 'guid-orange', 'name' => 'Kinza апельсин',
            'quantity' => 2, 'price' => 54, 'total' => 108,
        ]);

        $xml = $this->buildItemsXml($order->fresh()->load('items'));

        $this->assertStringContainsString('Доставка', $xml);
        $this->assertStringContainsString('100.00', $xml);
        $this->assertStringContainsString('delivery', $xml); // Ид номенклатуры доставки
    }

    public function test_export_skips_delivery_when_zero(): void
    {
        $order = $this->makeOrder(['shop_order_id' => 301, 'delivery_cost' => 0]);
        $order->items()->create([
            'external_id' => 'guid-orange', 'name' => 'Kinza апельсин',
            'quantity' => 2, 'price' => 54, 'total' => 108,
        ]);

        $xml = $this->buildItemsXml($order->fresh()->load('items'));

        $this->assertStringNotContainsString('Доставка', $xml);
    }

    private function buildItemsXml(Order $order): string
    {
        $handler = new OrderExportHandler();
        $method = new \ReflectionMethod($handler, 'buildOrderItems');
        $method->setAccessible(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        /** @var \DOMElement $el */
        $el = $method->invoke($handler, $dom, $order);

        return $dom->saveXML($el);
    }
}
