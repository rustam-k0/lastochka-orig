<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-265/MID-267: эхо order.updated от сервисов на общей БД (delivery_back,
 * purchase_back) не применяется к заказу, а служебная строка замены
 * `{external_id}_origin` не превращается в позицию заказа.
 *
 * Без гардов курьерка получала своё же событие обратно и заводила
 * заменённый товар второй позицией с количеством в граммах — курьер видел
 * «Мясо (куриная грудка) 1000 кг» рядом с товаром-заменой.
 */
class OrderEchoFromDeliveryGuardTest extends TestCase
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

    private function makeShop(): \App\Models\Shop
    {
        return ShopFactory::new()->create([
            'code' => 'echo-shop-' . uniqid(),
            'title' => 'Echo Shop',
            'commission' => 0, 'deposit' => 0, 'payment_type_id' => Type::PAYMENT_CASH,
            'slot_time' => 30, 'slot_max_orders' => 10, 'type_id' => Type::DELIVERY_COURIER,
            'longitude' => 37.6, 'latitude' => 55.7,
        ]);
    }

    private function makeProduct(int $shopId, string $title): Product
    {
        $product = new Product();
        $product->shop_id = $shopId;
        $product->title = $title;
        $product->save();

        return $product;
    }

    private function makeOrder(int $shopId): Order
    {
        $client = Client::create([
            'phone' => '7' . substr((string) mt_rand(1000000000, 9999999999), 0, 10),
            'name' => 'Echo',
            'is_authorized' => true,
        ]);

        return Order::withoutEvents(fn () => Order::create([
            'uuid'            => 'echo-order-' . uniqid(),
            'client_id'       => $client->id,
            'shop_id'         => $shopId,
            'type_id'         => Type::DELIVERY_COURIER,
            'status_id'       => Status::ORDER_ASSEMBLED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount'          => 300,
            'origin_amount'   => 300,
            'paid'            => false,
            'slot_start_date' => now(),
            'slot_end_date'   => now(),
        ]));
    }

    public function test_update_from_delivery_echo_is_skipped(): void
    {
        $order = $this->makeOrder($this->makeShop()->id);

        $result = $this->transformer->beforeUpdate(
            $order,
            ['event_id' => 'evt-1', 'id' => $order->uuid, 'event_source' => 'delivery'],
            ['status_id' => Status::ORDER_CREATED, 'amount' => 999]
        );

        $this->assertNull($result, 'Эхо от delivery_back должно пропускаться целиком');
    }

    public function test_update_from_storefront_is_processed(): void
    {
        $order = $this->makeOrder($this->makeShop()->id);

        $result = $this->transformer->beforeUpdate(
            $order,
            ['event_id' => 'evt-2', 'id' => $order->uuid, 'event_source' => 'storefront'],
            ['amount' => 999]
        );

        $this->assertNotNull($result);
        $this->assertSame(999, $result['amount']);
    }

    public function test_update_without_event_source_is_processed(): void
    {
        $order = $this->makeOrder($this->makeShop()->id);

        $result = $this->transformer->beforeUpdate(
            $order,
            ['event_id' => 'evt-3', 'id' => $order->uuid],
            ['amount' => 777]
        );

        $this->assertNotNull($result, 'События без event_source обрабатываются как раньше');
        $this->assertSame(777, $result['amount']);
    }

    public function test_create_from_delivery_echo_is_skipped(): void
    {
        $result = $this->transformer->beforeCreate(
            ['event_id' => 'evt-4', 'id' => 'ext-1', 'event_source' => 'purchase'],
            ['amount' => 100]
        );

        $this->assertNull($result, 'Эхо от purchase_back не должно создавать заказ');
    }

    public function test_origin_row_does_not_create_order_item(): void
    {
        $shop = $this->makeShop();
        $shopCode = $shop->code;

        $replaced = $this->makeProduct($shop->id, 'Мясо Производство (куриная грудка)');
        $replacement = $this->makeProduct($shop->id, 'Мясо цыплят тушеное');

        EventSyncMetadata::create([
            'entity_type' => 'products', 'source' => 'storefront',
            'external_id' => 'EXT-REPLACED', 'shop_code' => $shopCode,
            'entity_id' => $replaced->id, 'last_event_timestamp' => now(),
        ]);
        EventSyncMetadata::create([
            'entity_type' => 'products', 'source' => 'storefront',
            'external_id' => 'EXT-REPLACEMENT', 'shop_code' => $shopCode,
            'entity_id' => $replacement->id, 'last_event_timestamp' => now(),
        ]);

        $order = $this->makeOrder($shop->id);

        $items = [
            [
                'id' => '60_origin', 'source' => 'storefront',
                'product_id' => 'EXT-REPLACED', 'product_source' => 'storefront',
                'product_name' => 'Мясо Производство (куриная грудка)',
                'quantity' => 1000, 'unit_price' => 300, 'line_total' => 0,
                'item_status' => 'replaced',
            ],
            [
                'id' => '60', 'source' => 'storefront',
                'product_id' => 'EXT-REPLACEMENT', 'product_source' => 'storefront',
                'product_name' => 'Мясо цыплят тушеное',
                'quantity' => 2, 'unit_price' => 35.1, 'line_total' => 70.2,
                'item_status' => 'added',
            ],
        ];

        $processed = $this->callProcessOrderItems($order, $items, $shopCode);

        $this->assertCount(1, $processed, 'Обработана только реальная позиция-замена');

        $created = OrderItem::whereIn('id', $processed)->get();
        $this->assertSame([$replacement->id], $created->pluck('product_id')->all());

        $this->assertSame(
            0,
            OrderItem::where('order_id', $order->id)->where('product_id', $replaced->id)->count(),
            'Заменённый товар не должен появляться отдельной позицией заказа'
        );

        $this->assertSame(
            0,
            EventSyncMetadata::where('entity_type', 'order_items')
                ->where('external_id', '60_origin')
                ->count(),
            'Под служебную строку не должна заводиться metadata'
        );
    }
}
