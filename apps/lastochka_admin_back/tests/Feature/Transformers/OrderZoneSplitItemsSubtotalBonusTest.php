<?php
// tests/Feature/Transformers/OrderZoneSplitItemsSubtotalBonusTest.php

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
 * MEN-IN-DEV-11267: items_subtotal должен пересчитываться по позициям
 * каждой зоны (по аналогии с origin_amount), bonus_earned — обнуляться
 * у дочерних подзаказов (по аналогии с bonus_spent) — бонусы относятся
 * к заказу целиком, не делятся по зонам сборки.
 */
class OrderZoneSplitItemsSubtotalBonusTest extends TestCase
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
            'code'            => 'items-subtotal-split-' . uniqid(),
            'title'           => 'Items Subtotal Split Shop',
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
            'uuid'            => 'items-subtotal-split-' . uniqid(),
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

    public function test_items_subtotal_recalculated_per_zone_bonus_earned_reset(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();

        $zoneA = $this->makeZone('Зона A');
        $zoneB = $this->makeZone('Зона B');
        $catA  = $this->makeCategoryWithZone($zoneA);
        $catB  = $this->makeCategoryWithZone($zoneB);

        $prodA = $this->makeProductInCategory($shop->id, $catA);
        $prodB = $this->makeProductInCategory($shop->id, $catB);

        $order = $this->makeOrder($shop, $client, [
            'bonus_earned' => 50,
        ]);
        $this->makeOrderItem($order, $prodA, ['price' => 300, 'quantity' => 1]);
        $this->makeOrderItem($order, $prodB, ['price' => 100, 'quantity' => 2]);

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $this->callSplit($order);

        $children = Order::where('parent_order_id', $order->id)->get()->keyBy('assembly_zone_id');

        $this->assertSame(300, (int) $children[$zoneA->id]->items_subtotal, 'Зона A: 300 * 1');
        $this->assertSame(200, (int) $children[$zoneB->id]->items_subtotal, 'Зона B: 100 * 2');

        foreach ($children as $child) {
            $this->assertSame(0, (int) $child->bonus_earned, 'Бонусы не делятся по зонам сборки');
        }
    }

    public function test_items_subtotal_preserves_kopecks_when_split_by_zone(): void
    {
        // IMP-1: items_subtotal — decimal(15,2), копейки не должны обрезаться
        // при пересчёте по зонам сборки в splitOrderByZones().
        $shop   = $this->makeShop();
        $client = $this->makeClient();

        $zoneA = $this->makeZone('Зона A (копейки)');
        $zoneB = $this->makeZone('Зона B (копейки)');
        $catA  = $this->makeCategoryWithZone($zoneA);
        $catB  = $this->makeCategoryWithZone($zoneB);

        $prodA = $this->makeProductInCategory($shop->id, $catA);
        $prodB = $this->makeProductInCategory($shop->id, $catB);

        $order = $this->makeOrder($shop, $client);
        // 99.99 * 1 = 99.99 — не круглое число, должно сохраниться с копейками
        $this->makeOrderItem($order, $prodA, ['price' => 99.99, 'quantity' => 1]);
        $this->makeOrderItem($order, $prodB, ['price' => 100, 'quantity' => 2]);

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $this->callSplit($order);

        $children = Order::where('parent_order_id', $order->id)->get()->keyBy('assembly_zone_id');

        $this->assertEquals(99.99, (float) $children[$zoneA->id]->items_subtotal, 'Зона A: 99.99 * 1, копейки сохранены');
        $this->assertEquals(200, (float) $children[$zoneB->id]->items_subtotal, 'Зона B: 100 * 2');
    }
}
