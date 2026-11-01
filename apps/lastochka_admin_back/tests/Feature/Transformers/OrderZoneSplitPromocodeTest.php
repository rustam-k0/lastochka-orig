<?php

namespace Tests\Feature\Transformers;

use App\Models\AdminSetting;
use App\Models\AssemblyZone;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MEN-IN-DEV-11276: promocode_code должен копироваться из родительского
 * заказа в дочерние подзаказы при расщеплении по зонам сборки — иначе при
 * просмотре подзаказа напрямую (не через родителя) код промокода теряется,
 * хотя discount_percent уже копируется.
 */
class OrderZoneSplitPromocodeTest extends TestCase
{
    use DatabaseTransactions;

    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer();

        AdminSetting::setValue(
            'project_modules',
            json_encode(['assembly_zones' => ['enabled' => true]])
        );
    }

    private function callSplit(Order $order): void
    {
        $method = new \ReflectionMethod($this->transformer, 'splitOrderByZones');
        $method->setAccessible(true);
        $method->invoke($this->transformer, $order);
    }

    private function makeShop(): \App\Models\Shop
    {
        return ShopFactory::new()->create([
            'code'            => 'promo-zone-split-' . uniqid(),
            'title'           => 'Promo Zone Split Shop',
            'commission'      => 0,
            'deposit'         => 0,
            'payment_type_id' => Type::PAYMENT_CASH,
            'slot_time'       => 30,
            'slot_max_orders' => 10,
            'type_id'         => Type::DELIVERY_COURIER,
            'longitude'       => 37.6,
            'latitude'        => 55.7,
        ]);
    }

    private function makeZone(string $title): AssemblyZone
    {
        return AssemblyZone::create([
            'title'     => $title,
            'color'     => '#' . substr(md5($title), 0, 6),
            'is_active' => true,
            'position'  => 1,
        ]);
    }

    private function makeCategoryWithZone(AssemblyZone $zone): Category
    {
        return Category::create([
            'title'            => 'Cat-' . uniqid(),
            'assembly_zone_id' => $zone->id,
            'is_active'        => true,
        ]);
    }

    private function makeProductInCategory(int $shopId, Category $category): Product
    {
        $product = Product::withoutEvents(fn () => Product::create([
            'title'        => 'Product-' . uniqid(),
            'articul'      => 'art-' . uniqid(),
            'shop_id'      => $shopId,
            'status_id'    => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
        ]));

        $product->categories()->attach($category->id);

        return $product;
    }

    private function makeClient(): Client
    {
        return Client::create([
            'phone'         => '7' . random_int(1000000000, 9999999999),
            'is_authorized' => true,
        ]);
    }

    private function makeOrder(\App\Models\Shop $shop, Client $client, array $extra = []): Order
    {
        return Order::withoutEvents(fn () => Order::create(array_merge([
            'uuid'            => 'promo-zone-split-' . uniqid(),
            'client_id'       => $client->id,
            'shop_id'         => $shop->id,
            'type_id'         => Type::DELIVERY_COURIER,
            'status_id'       => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount'          => 500,
            'origin_amount'   => 500,
            'paid'            => false,
            'slot_start_date' => now(),
            'slot_end_date'   => now(),
        ], $extra)));
    }

    private function makeOrderItem(Order $order, Product $product, array $extra = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'price'      => 200,
        ], $extra));
    }

    public function test_promocode_code_copied_to_child_orders_on_zone_split(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();

        $zoneA = $this->makeZone('Промо-зона A');
        $zoneB = $this->makeZone('Промо-зона B');
        $catA  = $this->makeCategoryWithZone($zoneA);
        $catB  = $this->makeCategoryWithZone($zoneB);

        $prodA = $this->makeProductInCategory($shop->id, $catA);
        $prodB = $this->makeProductInCategory($shop->id, $catB);

        $order = $this->makeOrder($shop, $client, [
            'discount_percent' => 10,
            'promocode_code'   => 'SUMMER10',
        ]);
        $this->makeOrderItem($order, $prodA, ['price' => 300]);
        $this->makeOrderItem($order, $prodB, ['price' => 200]);

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $this->callSplit($order);

        $children = Order::where('parent_order_id', $order->id)->get();
        $this->assertCount(2, $children, 'Заказ с товарами в двух зонах должен разбиться на 2 под-заказа');

        foreach ($children as $child) {
            $this->assertSame(
                'SUMMER10',
                $child->promocode_code,
                'Дочерний подзаказ должен унаследовать promocode_code родителя'
            );
        }
    }

    public function test_no_promocode_on_parent_leaves_children_without_promocode_code(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();

        $zoneA = $this->makeZone('Промо-зона без кода A');
        $zoneB = $this->makeZone('Промо-зона без кода B');
        $catA  = $this->makeCategoryWithZone($zoneA);
        $catB  = $this->makeCategoryWithZone($zoneB);

        $prodA = $this->makeProductInCategory($shop->id, $catA);
        $prodB = $this->makeProductInCategory($shop->id, $catB);

        $order = $this->makeOrder($shop, $client);
        $this->makeOrderItem($order, $prodA, ['price' => 100]);
        $this->makeOrderItem($order, $prodB, ['price' => 200]);

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $this->callSplit($order);

        $children = Order::where('parent_order_id', $order->id)->get();
        $this->assertCount(2, $children);

        foreach ($children as $child) {
            $this->assertNull($child->promocode_code);
        }
    }
}
