<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\AdminSetting;
use App\Models\AssemblyZone;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-280: снимок условий доставки из события заказа витрины.
 */
class OrderDeliveryPriceSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private const SNAPSHOT = [
        'base_price' => 200,
        'ranges' => [
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ],
        'checkout_price' => 100,
    ];

    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer();
    }

    private function eventPayload(array $extra = []): array
    {
        return array_merge([
            'id' => 'mid280-order-' . uniqid(),
            'shop_code' => 'default',
            'event_type' => 'created',
            'status' => 'created',
            'payment_type' => 'cash',
            'shipping_method' => 'courier',
            'grand_total' => 1600,
            'items_subtotal' => 1500,
            'shipping_total' => 100,
        ], $extra);
    }

    private function client(): Client
    {
        return Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'is_authorized' => true,
        ]);
    }

    private function order(array $extra = [], ?Shop $shop = null): Order
    {
        // Клиент создаётся до withoutEvents: иначе не сработает его хук creating (пароль)
        $clientId = $this->client()->id;

        return Order::withoutEvents(fn () => Order::create(array_merge([
            'uuid' => 'mid280-order-' . uniqid(),
            'client_id' => $clientId,
            'shop_id' => ($shop ?? Shop::firstOrFail())->id,
            'type_id' => Type::DELIVERY_COURIER,
            'status_id' => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount' => 1600,
            'origin_amount' => 1500,
            'paid' => false,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'delivery_price' => 100,
        ], $extra)));
    }

    public function test_from_event_maps_snapshot(): void
    {
        $attributes = $this->transformer->fromEvent($this->eventPayload([
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $this->assertSame(self::SNAPSHOT, $attributes['delivery_price_snapshot']);
    }

    public function test_from_event_without_key_does_not_touch_snapshot(): void
    {
        $attributes = $this->transformer->fromEvent($this->eventPayload());

        $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes);
    }

    public function test_from_event_decodes_json_string_snapshot(): void
    {
        $attributes = $this->transformer->fromEvent($this->eventPayload([
            'delivery_price_snapshot' => json_encode(self::SNAPSHOT),
        ]));

        $this->assertSame(self::SNAPSHOT, $attributes['delivery_price_snapshot']);
    }

    public function test_from_event_ignores_non_array_snapshot(): void
    {
        // null, число, битая строка и JSON-скаляр атрибута не дают: колонка не трогается
        foreach ([null, '', 'oops', 42, 4.5, true, '42', '"text"', '{"base_price": 200', 'null'] as $value) {
            $attributes = $this->transformer->fromEvent($this->eventPayload(['delivery_price_snapshot' => $value]));

            $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes, var_export($value, true));
        }
    }

    public function test_snapshot_from_event_is_stored_on_create(): void
    {
        $attributes = $this->transformer->fromEvent($this->eventPayload([
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $clientId = $this->client()->id;
        $order = Order::withoutEvents(fn () => Order::create($attributes + ['client_id' => $clientId]));

        $this->assertEquals(self::SNAPSHOT, $order->fresh()->delivery_price_snapshot);
    }

    public function test_update_does_not_overwrite_existing_snapshot(): void
    {
        // Снимок в курьерке мог пересобрать delivery_back (смена магазина заказа)
        $rebuilt = ['base_price' => 150, 'ranges' => [], 'checkout_price' => 100];
        $order = $this->order(['delivery_price_snapshot' => $rebuilt]);

        $transformed = $this->transformer->fromEvent($this->eventPayload([
            'id' => $order->uuid,
            'event_type' => 'updated',
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $result = $this->transformer->beforeUpdate($order, ['event_type' => 'updated'], $transformed);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('delivery_price_snapshot', $result);
    }

    public function test_update_fills_missing_snapshot(): void
    {
        $order = $this->order();

        $transformed = $this->transformer->fromEvent($this->eventPayload([
            'id' => $order->uuid,
            'event_type' => 'updated',
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $result = $this->transformer->beforeUpdate($order, ['event_type' => 'updated'], $transformed);

        $this->assertSame(self::SNAPSHOT, $result['delivery_price_snapshot']);
    }

    /**
     * order.updated витрины через настоящий консьюмер пакета (fromEvent → beforeUpdate → update).
     */
    private function consumeUpdate(Order $order, array $extra): string
    {
        $shop = Shop::findOrFail($order->shop_id);
        $clientExternalId = 'mid280-client-' . uniqid();

        EventSyncMetadata::query()->create([
            'entity_type' => 'customers',
            'entity_id' => $order->client_id,
            'external_id' => $clientExternalId,
            'source' => 'storefront',
            'shop_code' => $shop->code,
        ]);
        EventSyncMetadata::query()->create([
            'entity_type' => 'orders',
            'entity_id' => $order->id,
            'external_id' => $order->uuid,
            'source' => 'storefront',
            'shop_code' => $shop->code,
        ]);

        $consumer = new EventConsumer('orders', config('rabbitmq-events.entities.orders'));

        return $consumer->processEvent($this->eventPayload(array_merge([
            'event_id' => 'mid280-update-' . uniqid(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => $order->uuid,
            'source' => 'storefront',
            'shop_code' => $shop->code,
            'client_id' => $clientExternalId,
            'client_source' => 'storefront',
        ], $extra)));
    }

    public function test_update_through_consumer_fills_missing_snapshot(): void
    {
        $order = $this->order();

        $this->assertSame('ack', $this->consumeUpdate($order, ['delivery_price_snapshot' => self::SNAPSHOT]));
        $this->assertEquals(self::SNAPSHOT, $order->fresh()->delivery_price_snapshot);
    }

    public function test_update_through_consumer_keeps_existing_snapshot(): void
    {
        $rebuilt = ['base_price' => 150, 'ranges' => [], 'checkout_price' => 100];
        $order = $this->order(['delivery_price_snapshot' => $rebuilt]);

        $this->assertSame('ack', $this->consumeUpdate($order, ['delivery_price_snapshot' => self::SNAPSHOT]));
        $this->assertEquals($rebuilt, $order->fresh()->delivery_price_snapshot);
    }

    public function test_update_through_consumer_without_key_keeps_snapshot(): void
    {
        $order = $this->order(['delivery_price_snapshot' => self::SNAPSHOT]);

        $this->assertSame('ack', $this->consumeUpdate($order, []));
        $this->assertEquals(self::SNAPSHOT, $order->fresh()->delivery_price_snapshot);
    }

    public function test_outgoing_event_does_not_carry_snapshot(): void
    {
        $order = $this->order(['delivery_price_snapshot' => self::SNAPSHOT]);
        $order->load(['orderItems.product', 'orderItems.originProduct', 'client', 'shop', 'clientInfo']);

        $payload = $this->transformer->toEvent($order);

        $this->assertArrayNotHasKey('delivery_price_snapshot', $payload);
        $this->assertSame(100.0, $payload['shipping_total']);
    }

    public function test_zone_split_children_do_not_get_snapshot(): void
    {
        AdminSetting::setValue('project_modules', json_encode(['assembly_zones' => ['enabled' => true]]));

        $shop = ShopFactory::new()->create([
            'code' => 'mid280-split-' . uniqid(),
            'title' => 'MID-280 split',
            'commission' => 0,
            'deposit' => 0,
            'payment_type_id' => Type::PAYMENT_CASH,
            'slot_time' => 30,
            'slot_max_orders' => 10,
            'type_id' => Type::DELIVERY_COURIER,
            'longitude' => 37.6,
            'latitude' => 55.7,
        ]);

        $order = $this->order(['delivery_price_snapshot' => self::SNAPSHOT], $shop);

        foreach (['A', 'B'] as $suffix) {
            $zone = AssemblyZone::create([
                'title' => 'MID-280 зона ' . $suffix . ' ' . uniqid(),
                'color' => '#00aa0' . ($suffix === 'A' ? '1' : '2'),
                'is_active' => true,
                'position' => 1,
            ]);
            $category = Category::create([
                'title' => 'MID-280 cat ' . uniqid(),
                'assembly_zone_id' => $zone->id,
                'is_active' => true,
            ]);
            $product = Product::withoutEvents(fn () => Product::create([
                'title' => 'MID-280 product ' . uniqid(),
                'articul' => 'mid280-' . uniqid(),
                'shop_id' => $shop->id,
                'status_id' => Status::CODE_ACTIVE,
                'unit_type_id' => Type::UNIT_PIECE,
            ]));
            $product->categories()->attach($category->id);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 750,
            ]);
        }

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $split = new \ReflectionMethod($this->transformer, 'splitOrderByZones');
        $split->setAccessible(true);
        $split->invoke($this->transformer, $order);

        $children = Order::where('parent_order_id', $order->id)->get();

        $this->assertCount(2, $children);
        foreach ($children as $child) {
            $this->assertNull($child->delivery_price_snapshot, 'снимок живёт на родителе');
        }
        $this->assertEquals(self::SNAPSHOT, $order->fresh()->delivery_price_snapshot);
    }
}
