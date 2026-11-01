<?php

namespace Tests\Feature\Aggregator;

use App\Aggregator\Events\OrderPlaced;
use App\Aggregator\Services\OrderAggregatorSplitter;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformProduct;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты расщепления заказа по магазинам (FOODLINK-10874, §6.5, §15.1).
 */
class OrderAggregatorSplitterTest extends TestCase
{
    use DatabaseTransactions;

    private OrderAggregatorSplitter $splitter;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->splitter = new OrderAggregatorSplitter();
        $this->clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;
    }

    private function makeShop(string $code): Shop
    {
        return Shop::create(['code' => $code, 'title' => 'Shop ' . $code]);
    }

    private function makeProduct(Shop $shop): Product
    {
        return Product::withoutEvents(fn () => Product::create([
            'title' => 'Товар ' . uniqid(),
            'articul' => uniqid('art_'),
            'shop_id' => $shop->id,
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
        ]));
    }

    private function makeOrder(): Order
    {
        return Order::create([
            'uuid' => (string) random_int(100000, 999999),
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_CREATED,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]);
    }

    private function addItem(Order $order, Product $product, int $price = 1000, array $attrs = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $price,
        ], $attrs));
    }

    public function test_child_amount_is_line_total_price_times_quantity(): void
    {
        $shopA = $this->makeShop('dagbars');
        $shopB = $this->makeShop('tsk');
        $order = $this->makeOrder();
        // price — цена за единицу; amount дочернего должен быть price*quantity.
        $this->addItem($order, $this->makeProduct($shopA), 1000, ['quantity' => 2]); // 2000
        $this->addItem($order, $this->makeProduct($shopB), 500, ['quantity' => 3]);  // 1500

        $this->splitter->handle(new OrderPlaced($order->refresh()));

        $childA = Order::where('parent_order_id', $order->id)->where('shop_id', $shopA->id)->first();
        $childB = Order::where('parent_order_id', $order->id)->where('shop_id', $shopB->id)->first();
        $this->assertEquals(2000, (int) $childA->amount);
        $this->assertEquals(1500, (int) $childB->amount);
    }

    public function test_single_shop_order_is_marked_not_split(): void
    {
        $shop = $this->makeShop('dagbars');
        $order = $this->makeOrder();
        $this->addItem($order, $this->makeProduct($shop));
        $this->addItem($order, $this->makeProduct($shop));

        $this->splitter->handle(new OrderPlaced($order->refresh()));

        $order->refresh();
        $this->assertSame('shop', $order->split_strategy);
        $this->assertSame($shop->id, $order->shop_id);
        $this->assertSame(0, $order->childOrders()->count());
    }

    public function test_multi_shop_order_splits_into_children_by_product_shop(): void
    {
        $shopA = $this->makeShop('dagbars');
        $shopB = $this->makeShop('tsk');
        $order = $this->makeOrder();
        $itemA = $this->addItem($order, $this->makeProduct($shopA), 1500);
        $itemB = $this->addItem($order, $this->makeProduct($shopB), 2500);

        $this->splitter->handle(new OrderPlaced($order->refresh()));

        $order->refresh();
        $this->assertSame('shop', $order->split_strategy);
        $this->assertNull($order->shop_id, 'parent становится координатором');
        $this->assertSame(2, $order->childOrders()->count());

        // Позиции перенесены в дочерние (не скопированы — родитель пуст).
        $this->assertSame(0, $order->orderItems()->count());

        $childA = Order::where('parent_order_id', $order->id)->where('shop_id', $shopA->id)->first();
        $childB = Order::where('parent_order_id', $order->id)->where('shop_id', $shopB->id)->first();
        $this->assertNotNull($childA);
        $this->assertNotNull($childB);
        $this->assertSame('shop', $childA->split_strategy);
        $this->assertSame($itemA->id, $childA->orderItems()->first()->id);
        $this->assertSame($itemB->id, $childB->orderItems()->first()->id);
        $this->assertEquals(1500, (int) $childA->amount);
        $this->assertEquals(2500, (int) $childB->amount);
    }

    public function test_already_split_order_is_left_untouched(): void
    {
        $shopA = $this->makeShop('dagbars');
        $shopB = $this->makeShop('tsk');
        $order = $this->makeOrder();
        $order->update(['split_strategy' => 'shop']);
        $this->addItem($order, $this->makeProduct($shopA));
        $this->addItem($order, $this->makeProduct($shopB));

        $this->splitter->handle(new OrderPlaced($order->refresh()));

        $this->assertSame(0, $order->childOrders()->count());
    }

    public function test_child_order_is_not_resplit(): void
    {
        $shopA = $this->makeShop('dagbars');
        $parent = $this->makeOrder();
        $child = Order::create([
            'uuid' => $parent->uuid . '-1',
            'parent_order_id' => $parent->id,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_CREATED,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]);
        $this->addItem($child, $this->makeProduct($shopA));

        $this->splitter->handle(new OrderPlaced($child->refresh()));

        $this->assertNull($child->fresh()->split_strategy);
    }

    public function test_offer_id_takes_precedence_for_shop_resolution(): void
    {
        $shopA = $this->makeShop('dagbars');
        $shopB = $this->makeShop('tsk');
        // Товар физически в shopA, но оффер привязан к shopB → группируем по офферу.
        $product = $this->makeProduct($shopA);
        $platform = PlatformProduct::create(['canonical_title' => 'X', 'is_active' => true]);
        $offer = ProductOffer::create([
            'platform_product_id' => $platform->id,
            'shop_id' => $shopB->id,
            'local_product_id' => $product->id,
            'price' => 1000,
            'qty' => 5,
            'min_qty' => 1,
        ]);

        $order = $this->makeOrder();
        $this->addItem($order, $product, 1000, ['offer_id' => $offer->id]);
        $this->addItem($order, $this->makeProduct($shopA), 1000);

        $this->splitter->handle(new OrderPlaced($order->refresh()));

        $order->refresh();
        $this->assertSame(2, $order->childOrders()->count());
        $this->assertNotNull(Order::where('parent_order_id', $order->id)->where('shop_id', $shopB->id)->first());
    }

    public function test_meta_external_shop_code_resolves_shop(): void
    {
        $shopA = $this->makeShop('dagbars');
        $shopB = $this->makeShop('tsk');
        $product = $this->makeProduct($shopA);

        $order = $this->makeOrder();
        // meta.external_shop_code указывает на shopB (как из payload Bagisto).
        $this->addItem($order, $product, 1000, ['meta' => ['external_shop_code' => 'tsk']]);
        $this->addItem($order, $this->makeProduct($shopA), 1000);

        $this->splitter->handle(new OrderPlaced($order->refresh()));

        $order->refresh();
        $this->assertSame(2, $order->childOrders()->count());
        $this->assertNotNull(Order::where('parent_order_id', $order->id)->where('shop_id', $shopB->id)->first());
    }
}
