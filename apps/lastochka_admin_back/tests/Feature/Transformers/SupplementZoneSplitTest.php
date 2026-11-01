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
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Task 16: splitOrderByZones — дочерняя добавка в свою зону сборки.
 *
 * Проверяет:
 * 1. Дочерняя позиция-добавка группируется по зоне СВОЕГО товара (не родителя).
 * 2. source_supplement_value_id сохраняется при копировании в под-заказ.
 * 3. parent_id маппится: если родитель в том же под-заказе — новый id; иначе null.
 * 4. Обычные заказы без добавок не ломаются.
 */
class SupplementZoneSplitTest extends TestCase
{
    use DatabaseTransactions;

    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer();

        // Включаем модуль assembly_zones
        AdminSetting::setValue(
            'project_modules',
            json_encode(['assembly_zones' => ['enabled' => true]])
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function callSplit(Order $order): void
    {
        $method = new \ReflectionMethod($this->transformer, 'splitOrderByZones');
        $method->setAccessible(true);
        $method->invoke($this->transformer, $order);
    }

    private function makeShop(): \App\Models\Shop
    {
        return ShopFactory::new()->create([
            'code'            => 'zone-split-' . uniqid(),
            'title'           => 'Zone Split Shop',
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

    /** Создаём категорию, привязываем её к зоне. */
    private function makeCategoryWithZone(AssemblyZone $zone): Category
    {
        // Не используем withoutEvents — boot() Category генерирует 'link' в creating
        return Category::create([
            'title'            => 'Cat-' . uniqid(),
            'assembly_zone_id' => $zone->id,
            'is_active'        => true,
        ]);
    }

    /** Создаём товар и прикрепляем к нужной категории. */
    private function makeProductInCategory(int $shopId, Category $category): Product
    {
        $product = Product::withoutEvents(fn () => Product::create([
            'title'       => 'Product-' . uniqid(),
            'articul'     => 'art-' . uniqid(),
            'shop_id'     => $shopId,
            'status_id'   => Status::CODE_ACTIVE,
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

    private function makeOrder(\App\Models\Shop $shop, Client $client): Order
    {
        return Order::withoutEvents(fn () => Order::create([
            'uuid'            => 'zone-split-' . uniqid(),
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
        ]));
    }

    private function makeOrderItem(Order $order, Product $product, array $extra = []): OrderItem
    {
        // Не используем withoutEvents — boot() OrderItem устанавливает status_id в creating
        return OrderItem::create(array_merge([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'price'      => 200,
        ], $extra));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Tests
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Основной кейс Task 16:
     * Товар-добавка (sauce) привязан к ДРУГОЙ зоне чем родитель (main).
     * После splitOrderByZones:
     *  - создаются 2 под-заказа (зона A и зона B).
     *  - child-позиция-добавка оказывается в под-заказе зоны B (своей зоны).
     *  - source_supplement_value_id сохранён.
     *  - parent_id = null (родитель в другом под-заказе).
     */
    public function test_supplement_in_different_zone_splits_to_own_suborder(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();

        $zoneA = $this->makeZone('Зона A');
        $zoneB = $this->makeZone('Зона B');

        $catA = $this->makeCategoryWithZone($zoneA);
        $catB = $this->makeCategoryWithZone($zoneB);

        $mainProduct  = $this->makeProductInCategory($shop->id, $catA); // зона A
        $sauceProduct = $this->makeProductInCategory($shop->id, $catB); // зона B

        // supplement + value (для source_supplement_value_id)
        $supplement = Supplement::create([
            'product_id' => $mainProduct->id,
            'title'      => 'Соус',
            'type'       => Supplement::TYPE_MANY,
        ]);
        $suppValue = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Сырный соус',
            'product_id'    => $sauceProduct->id,
        ]);

        $order    = $this->makeOrder($shop, $client);
        $rootItem = $this->makeOrderItem($order, $mainProduct, ['price' => 300]);
        $childItem = $this->makeOrderItem($order, $sauceProduct, [
            'price'                    => 50,
            'parent_id'                => $rootItem->id,
            'source_supplement_value_id' => $suppValue->id,
        ]);

        // Загружаем релейшены как делает splitOrderByZones
        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $this->callSplit($order);

        // Должно быть 2 под-заказа
        $children = Order::where('parent_order_id', $order->id)->get();
        $this->assertCount(2, $children, 'Должно создаться 2 под-заказа (зона A + зона B)');

        // Найдём под-заказ зоны B
        $childOrderZoneB = $children->firstWhere('assembly_zone_id', $zoneB->id);
        $this->assertNotNull($childOrderZoneB, 'Должен быть под-заказ для зоны B');

        // Позиция-добавка (sauceProduct) должна быть в под-заказе зоны B
        $copiedSauce = OrderItem::where('order_id', $childOrderZoneB->id)
            ->where('product_id', $sauceProduct->id)
            ->first();
        $this->assertNotNull($copiedSauce, 'Скопированная добавка должна быть в под-заказе зоны B');

        // source_supplement_value_id сохранён
        $this->assertSame(
            $suppValue->id,
            $copiedSauce->source_supplement_value_id,
            'source_supplement_value_id должен сохраниться при копировании'
        );

        // parent_id = null (родитель в другом под-заказе — зона A)
        $this->assertNull(
            $copiedSauce->parent_id,
            'parent_id должен быть null когда родитель в другом под-заказе'
        );
    }

    /**
     * Если добавка в ТОЙ ЖЕ зоне что родитель:
     * - обе позиции попадают в один под-заказ.
     * - parent_id копированной добавки указывает на скопированный id родителя.
     * - source_supplement_value_id сохранён.
     */
    public function test_supplement_in_same_zone_keeps_parent_id_mapped(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();

        $zone = $this->makeZone('Единая зона');
        $cat  = $this->makeCategoryWithZone($zone);

        $mainProduct  = $this->makeProductInCategory($shop->id, $cat);
        $sauceProduct = $this->makeProductInCategory($shop->id, $cat); // та же зона

        $supplement = Supplement::create([
            'product_id' => $mainProduct->id,
            'title'      => 'Добавка',
            'type'       => Supplement::TYPE_MANY,
        ]);
        $suppValue = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Вариант',
            'product_id'    => $sauceProduct->id,
        ]);

        $order     = $this->makeOrder($shop, $client);
        $rootItem  = $this->makeOrderItem($order, $mainProduct, ['price' => 300]);
        $childItem = $this->makeOrderItem($order, $sauceProduct, [
            'price'                    => 50,
            'parent_id'                => $rootItem->id,
            'source_supplement_value_id' => $suppValue->id,
        ]);

        // Добавляем ещё один товар в другую зону, чтобы split вообще произошёл
        $zoneB  = $this->makeZone('Зона B для тригера');
        $catB   = $this->makeCategoryWithZone($zoneB);
        $prodB  = $this->makeProductInCategory($shop->id, $catB);
        $this->makeOrderItem($order, $prodB, ['price' => 150]);

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $this->callSplit($order);

        $childOrderZone = Order::where('parent_order_id', $order->id)
            ->where('assembly_zone_id', $zone->id)
            ->first();
        $this->assertNotNull($childOrderZone, 'Должен быть под-заказ для единой зоны');

        // Оба должны быть скопированы в один под-заказ
        $copiedRoot  = OrderItem::where('order_id', $childOrderZone->id)
            ->where('product_id', $mainProduct->id)->first();
        $copiedSauce = OrderItem::where('order_id', $childOrderZone->id)
            ->where('product_id', $sauceProduct->id)->first();

        $this->assertNotNull($copiedRoot, 'Скопированный root должен быть в под-заказе единой зоны');
        $this->assertNotNull($copiedSauce, 'Скопированная добавка должна быть в том же под-заказе');

        // parent_id должен указывать на скопированный root (не оригинальный)
        $this->assertSame(
            $copiedRoot->id,
            $copiedSauce->parent_id,
            'parent_id должен указывать на скопированный id родителя (не оригинальный)'
        );

        // source_supplement_value_id сохранён
        $this->assertSame(
            $suppValue->id,
            $copiedSauce->source_supplement_value_id,
            'source_supplement_value_id должен сохраниться при копировании'
        );
    }

    /**
     * Регрессия: обычный заказ без добавок (только root-позиции) не ломается.
     */
    public function test_regular_order_without_supplements_splits_correctly(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();

        $zoneA = $this->makeZone('Reg-Зона A');
        $zoneB = $this->makeZone('Reg-Зона B');

        $catA = $this->makeCategoryWithZone($zoneA);
        $catB = $this->makeCategoryWithZone($zoneB);

        $prodA = $this->makeProductInCategory($shop->id, $catA);
        $prodB = $this->makeProductInCategory($shop->id, $catB);

        $order = $this->makeOrder($shop, $client);
        $this->makeOrderItem($order, $prodA, ['price' => 100]);
        $this->makeOrderItem($order, $prodB, ['price' => 200]);

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $this->callSplit($order);

        $children = Order::where('parent_order_id', $order->id)->get();
        $this->assertCount(2, $children, 'Обычный заказ должен разбиться на 2 под-заказа');

        foreach ($children as $child) {
            $items = OrderItem::where('order_id', $child->id)->get();
            $this->assertCount(1, $items);
            $this->assertNull($items->first()->parent_id);
            $this->assertNull($items->first()->source_supplement_value_id);
        }
    }
}
