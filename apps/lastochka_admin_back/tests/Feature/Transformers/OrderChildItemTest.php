<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Task 15: приём дочерних позиций заказа (товарная добавка из номенклатуры)
 * при propagation storefront → admin (LASTOCHKI-JAMI-11211).
 *
 * Проверяет двухпроходное создание в processOrderItems:
 * сначала root, затем дочерние с parent_id из мапы line_id → local id.
 */
class OrderChildItemTest extends TestCase
{
    use DatabaseTransactions;

    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer;
    }

    private function callProcessOrderItems(Order $order, array $items, string $shopCode): array
    {
        $method = new \ReflectionMethod($this->transformer, 'processOrderItems');
        $method->setAccessible(true);

        return $method->invoke($this->transformer, $order, $items, $shopCode);
    }

    /**
     * Product::factory() недоступна (у Shop нет HasFactory) — строим вручную,
     * как принято в проекте (см. ProductCategory1cFieldTest).
     */
    private function makeProduct(int $shopId, string $title): Product
    {
        $product = new Product();
        $product->shop_id = $shopId;
        $product->title = $title;
        $product->save();

        return $product;
    }

    public function test_child_item_linked_to_root_with_parent_id(): void
    {
        $shop = ShopFactory::new()->create([
            'code' => 'task15-shop-' . uniqid(),
            'title' => 'Task15 Shop',
            'commission' => 0, 'deposit' => 0, 'payment_type_id' => Type::PAYMENT_CASH,
            'slot_time' => 30, 'slot_max_orders' => 10, 'type_id' => Type::DELIVERY_COURIER,
            'longitude' => 37.6, 'latitude' => 55.7,
        ]);
        $shopCode = $shop->code;

        // Корневой товар (бургер) и товар-добавка (соус)
        $mainProduct = $this->makeProduct($shop->id, 'Бургер');
        $sauceProduct = $this->makeProduct($shop->id, 'Сырный соус');

        // Локальный supplement + value, который ссылается на товар-добавку
        $supplement = Supplement::create([
            'product_id' => $mainProduct->id,
            'title'      => 'Соус',
            'type'       => Supplement::TYPE_MANY,
        ]);
        $localValue = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Сырный соус',
            'product_id'    => $sauceProduct->id,
        ]);

        // Metadata: external product ids → локальные (storefront source)
        EventSyncMetadata::create([
            'entity_type' => 'products', 'source' => 'storefront',
            'external_id' => 'EXT-MAIN', 'shop_code' => $shopCode,
            'entity_id' => $mainProduct->id, 'last_event_timestamp' => now(),
        ]);
        EventSyncMetadata::create([
            'entity_type' => 'products', 'source' => 'storefront',
            'external_id' => 'EXT-SAUCE', 'shop_code' => $shopCode,
            'entity_id' => $sauceProduct->id, 'last_event_timestamp' => now(),
        ]);

        $client = Client::create(['phone' => '7' . substr((string) mt_rand(1000000000, 9999999999), 0, 10), 'name' => 'Task15', 'is_authorized' => true]);

        $order = Order::withoutEvents(fn () => Order::create([
            'uuid'            => 'task15-order-' . uniqid(),
            'client_id'       => $client->id,
            'shop_id'         => $shop->id,
            'type_id'         => Type::DELIVERY_COURIER,
            'status_id'       => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount'          => 350,
            'origin_amount'   => 350,
            'paid'            => false,
            'slot_start_date' => now(),
            'slot_end_date'   => now(),
        ]));

        // Payload: root (line_id=10) + child (line_id=11, parent_line_id=10)
        $items = [
            [
                'id' => '10', 'source' => 'storefront',
                'product_id' => 'EXT-MAIN', 'product_source' => 'storefront',
                'product_name' => 'Бургер', 'quantity' => 1,
                'unit_price' => 300, 'unit_price_effective' => 300,
                'line_total' => 300,
                'line_id' => '10', 'parent_line_id' => null,
                'source_supplement_value_external' => null,
            ],
            [
                'id' => '11', 'source' => 'storefront',
                'product_id' => 'EXT-SAUCE', 'product_source' => 'storefront',
                'product_name' => 'Сырный соус', 'quantity' => 1,
                'unit_price' => 50, 'unit_price_effective' => 50,
                'line_total' => 50,
                'line_id' => '11', 'parent_line_id' => '10',
                'source_supplement_value_external' => (string) $localValue->id,
            ],
        ];

        $processedIds = $this->callProcessOrderItems($order, $items, $shopCode);

        $this->assertCount(2, $processedIds);

        $order->load('orderItems');
        $rootItem = $order->orderItems->firstWhere('product_id', $mainProduct->id);
        $childItem = $order->orderItems->firstWhere('product_id', $sauceProduct->id);

        $this->assertNotNull($rootItem);
        $this->assertNotNull($childItem);

        // Root без parent_id
        $this->assertNull($rootItem->parent_id);

        // Child ссылается на локальный id root-позиции
        $this->assertSame($rootItem->id, $childItem->parent_id);

        // source_supplement_value_id резолвится в локальный value
        $this->assertSame($localValue->id, $childItem->source_supplement_value_id);
    }

    public function test_plain_order_without_children_unaffected(): void
    {
        $shop = ShopFactory::new()->create([
            'code' => 'task15-plain-' . uniqid(),
            'title' => 'Task15 Plain',
            'commission' => 0, 'deposit' => 0, 'payment_type_id' => Type::PAYMENT_CASH,
            'slot_time' => 30, 'slot_max_orders' => 10, 'type_id' => Type::DELIVERY_COURIER,
            'longitude' => 37.6, 'latitude' => 55.7,
        ]);
        $shopCode = $shop->code;

        $product = $this->makeProduct($shop->id, 'Молоко');

        EventSyncMetadata::create([
            'entity_type' => 'products', 'source' => 'storefront',
            'external_id' => 'EXT-MILK', 'shop_code' => $shopCode,
            'entity_id' => $product->id, 'last_event_timestamp' => now(),
        ]);

        $client = Client::create(['phone' => '7' . substr((string) mt_rand(1000000000, 9999999999), 0, 10), 'name' => 'Task15', 'is_authorized' => true]);

        $order = Order::withoutEvents(fn () => Order::create([
            'uuid'            => 'task15-plain-order-' . uniqid(),
            'client_id'       => $client->id,
            'shop_id'         => $shop->id,
            'type_id'         => Type::DELIVERY_COURIER,
            'status_id'       => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount'          => 100,
            'origin_amount'   => 100,
            'paid'            => false,
            'slot_start_date' => now(),
            'slot_end_date'   => now(),
        ]));

        // Обычный заказ — payload без line_id/parent_line_id (как старые события)
        $items = [
            [
                'id' => '20', 'source' => 'storefront',
                'product_id' => 'EXT-MILK', 'product_source' => 'storefront',
                'product_name' => 'Молоко', 'quantity' => 1,
                'unit_price' => 100, 'unit_price_effective' => 100,
                'line_total' => 100,
            ],
        ];

        $processedIds = $this->callProcessOrderItems($order, $items, $shopCode);

        $this->assertCount(1, $processedIds);

        $order->load('orderItems');
        $item = $order->orderItems->firstWhere('product_id', $product->id);

        $this->assertNotNull($item);
        $this->assertNull($item->parent_id);
        $this->assertNull($item->source_supplement_value_id);
    }

    public function test_child_without_resolvable_parent_creates_item_without_parent(): void
    {
        $shop = ShopFactory::new()->create([
            'code' => 'task15-orphan-' . uniqid(),
            'title' => 'Task15 Orphan',
            'commission' => 0, 'deposit' => 0, 'payment_type_id' => Type::PAYMENT_CASH,
            'slot_time' => 30, 'slot_max_orders' => 10, 'type_id' => Type::DELIVERY_COURIER,
            'longitude' => 37.6, 'latitude' => 55.7,
        ]);
        $shopCode = $shop->code;

        $sauceProduct = $this->makeProduct($shop->id, 'Соус-сирота');

        EventSyncMetadata::create([
            'entity_type' => 'products', 'source' => 'storefront',
            'external_id' => 'EXT-ORPHAN', 'shop_code' => $shopCode,
            'entity_id' => $sauceProduct->id, 'last_event_timestamp' => now(),
        ]);

        $client = Client::create(['phone' => '7' . substr((string) mt_rand(1000000000, 9999999999), 0, 10), 'name' => 'Task15', 'is_authorized' => true]);

        $order = Order::withoutEvents(fn () => Order::create([
            'uuid'            => 'task15-orphan-order-' . uniqid(),
            'client_id'       => $client->id,
            'shop_id'         => $shop->id,
            'type_id'         => Type::DELIVERY_COURIER,
            'status_id'       => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount'          => 50,
            'origin_amount'   => 50,
            'paid'            => false,
            'slot_start_date' => now(),
            'slot_end_date'   => now(),
        ]));

        // Child указывает на несуществующий parent_line_id=999 (рассинхрон).
        // Должен создаться без parent_id, не упасть.
        $items = [
            [
                'id' => '30', 'source' => 'storefront',
                'product_id' => 'EXT-ORPHAN', 'product_source' => 'storefront',
                'product_name' => 'Соус-сирота', 'quantity' => 1,
                'unit_price' => 50, 'unit_price_effective' => 50,
                'line_total' => 50,
                'line_id' => '30', 'parent_line_id' => '999',
                'source_supplement_value_external' => null,
            ],
        ];

        $processedIds = $this->callProcessOrderItems($order, $items, $shopCode);

        $this->assertCount(1, $processedIds);

        $order->load('orderItems');
        $item = $order->orderItems->firstWhere('product_id', $sauceProduct->id);

        $this->assertNotNull($item);
        $this->assertNull($item->parent_id);
    }
}
