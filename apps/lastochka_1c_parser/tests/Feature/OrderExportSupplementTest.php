<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Exchange1C\OrderExportHandler;
use App\Services\OrderImportService;
use App\RabbitMQ\DTO\OrderEventDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Верификация: дочерняя позиция-добавка (item_status='added') выгружается
 * в 1С отдельным <Товар> с её external_id.
 *
 * Фича «Добавка из номенклатуры» (LASTOCHKI-JAMI-11211).
 * Task 21: Verify — выгрузка дочерней позиции в 1С отдельным <Товар>.
 */
class OrderExportSupplementTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────
    // Хелперы
    // ──────────────────────────────────────────

    private function makeBaseOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'shop_order_id' => 5001,
            'number'        => '202606230001',
            'status'        => Order::STATUS_ASSEMBLED,
            'source'        => Order::SOURCE_SHOP,
            'shop_id'       => 1,
            'customer_name' => 'Покупатель',
            'customer_phone' => '+79990001234',
            'total'         => 350,
            'delivery_cost' => 0,
            'received_at'   => now(),
        ], $attrs));
    }

    private function buildItemsXml(Order $order): string
    {
        $handler = new OrderExportHandler();
        $method  = new \ReflectionMethod($handler, 'buildOrderItems');
        $method->setAccessible(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        /** @var \DOMElement $el */
        $el = $method->invoke($handler, $dom, $order);

        return $dom->saveXML($el);
    }

    // ──────────────────────────────────────────
    // Тесты экспорта
    // ──────────────────────────────────────────

    /**
     * Позиция-добавка (item_status='added') сохраняется в order_items
     * через OrderImportService::syncItems и выгружается отдельным <Товар>
     * с external_id товара-добавки.
     */
    public function test_supplement_item_exported_as_separate_product(): void
    {
        $order = $this->makeBaseOrder();

        // Симулируем состав заказа который присылает курьерка:
        // основная позиция + позиция-добавка с item_status='added'
        $rawItems = [
            [
                'id'           => '10',
                'source'       => 'aladdin',
                'product_id'   => 'guid-main-product',
                'product_name' => 'Основной товар',
                'item_status'  => 'confirmed',
                'unit_price'   => 200.00,
                'quantity'     => 1,
                'line_total'   => 200.00,
            ],
            [
                'id'           => '20',
                'source'       => 'aladdin',
                'product_id'   => 'guid-supplement-product',
                'product_name' => 'Добавка из номенклатуры',
                'item_status'  => 'added',
                'unit_price'   => 150.00,
                'quantity'     => 1,
                'line_total'   => 150.00,
            ],
        ];

        $service = new OrderImportService();
        $service->syncItems($order, $rawItems);

        // Обе позиции должны быть сохранены
        $this->assertSame(2, $order->items()->count());
        $this->assertDatabaseHas('order_items', [
            'order_id'    => $order->id,
            'external_id' => 'guid-supplement-product',
            'name'        => 'Добавка из номенклатуры',
        ]);

        // Выгружаем в XML
        $order->load('items');
        $xml = $this->buildItemsXml($order);

        // Добавка должна присутствовать как отдельный <Товар> с её external_id
        $this->assertStringContainsString('<Ид>guid-supplement-product</Ид>', $xml);
        $this->assertStringContainsString('Добавка из номенклатуры', $xml);

        // Основная позиция тоже присутствует
        $this->assertStringContainsString('<Ид>guid-main-product</Ид>', $xml);
    }

    /**
     * Позиция-добавка (item_status='added') при прямом создании order_item
     * выгружается отдельным <Товар> — проверяем buildOrderItems напрямую.
     */
    public function test_supplement_item_in_buildOrderItems_produces_product_node(): void
    {
        $order = $this->makeBaseOrder(['shop_order_id' => 5002]);

        // Создаём позицию-добавку напрямую (как она лежит в order_items после syncItems)
        $order->items()->create([
            'external_id' => 'guid-supplement-direct',
            'name'        => 'Прямая добавка',
            'quantity'    => 2,
            'price'       => 75.00,
            'total'       => 150.00,
        ]);

        $xml = $this->buildItemsXml($order->fresh()->load('items'));

        // В XML должен быть <Товар> с Ид = guid-supplement-direct
        $this->assertStringContainsString('<Ид>guid-supplement-direct</Ид>', $xml);
        $this->assertStringContainsString('Прямая добавка', $xml);
        $this->assertStringContainsString('75.00', $xml);
        $this->assertStringContainsString('150.00', $xml);
    }

    /**
     * Заменённый оригинал замены (item_status='replaced') не попадает в order_items
     * и не выгружается в 1С. Добавка (item_status='added') при этом выгружается.
     */
    public function test_replaced_item_excluded_added_item_included(): void
    {
        $order = $this->makeBaseOrder(['shop_order_id' => 5003]);

        $rawItems = [
            [
                'id'           => '30',
                'product_id'   => 'guid-original',
                'product_name' => 'Оригинальный товар (заменён)',
                'item_status'  => 'replaced',
                'unit_price'   => 100.00,
                'quantity'     => 1,
                'line_total'   => 0.00,
            ],
            [
                'id'           => '31',
                'product_id'   => 'guid-supplement-replacement',
                'product_name' => 'Товар-замена (добавка)',
                'item_status'  => 'added',
                'unit_price'   => 120.00,
                'quantity'     => 1,
                'line_total'   => 120.00,
            ],
        ];

        $service = new OrderImportService();
        $service->syncItems($order, $rawItems);

        // Только добавка в БД
        $this->assertSame(1, $order->items()->count());
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'external_id' => 'guid-original']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'external_id' => 'guid-supplement-replacement']);

        $order->load('items');
        $xml = $this->buildItemsXml($order);

        // Добавка в XML
        $this->assertStringContainsString('<Ид>guid-supplement-replacement</Ид>', $xml);
        // Оригинал — нет
        $this->assertStringNotContainsString('guid-original', $xml);
    }

    /**
     * Запись _origin не попадает в order_items и не выгружается.
     */
    public function test_origin_suffix_item_excluded_from_export(): void
    {
        $order = $this->makeBaseOrder(['shop_order_id' => 5004]);

        $rawItems = [
            [
                'id'           => '40_origin',
                'product_id'   => 'guid-origin-item',
                'product_name' => 'Оригинал замены (_origin)',
                'unit_price'   => 80.00,
                'quantity'     => 1,
                'line_total'   => 80.00,
            ],
            [
                'id'           => '41',
                'product_id'   => 'guid-real-supplement',
                'product_name' => 'Реальная добавка',
                'item_status'  => 'added',
                'unit_price'   => 90.00,
                'quantity'     => 1,
                'line_total'   => 90.00,
            ],
        ];

        $service = new OrderImportService();
        $service->syncItems($order, $rawItems);

        $this->assertSame(1, $order->items()->count());

        $order->load('items');
        $xml = $this->buildItemsXml($order);

        $this->assertStringContainsString('<Ид>guid-real-supplement</Ид>', $xml);
        $this->assertStringNotContainsString('guid-origin-item', $xml);
    }
}
