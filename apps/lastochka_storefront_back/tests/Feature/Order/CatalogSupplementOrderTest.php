<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тест: при оформлении заказа дочерние cart_items (товарная добавка из каталога)
 * переносятся в дочерние order_items (LASTOCHKI-JAMI-11211, Task 7).
 */
final class CatalogSupplementOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_creates_child_item_for_catalog_supplement(): void
    {
        // --- Подготовка ---
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price'   => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();

        /** @var Product $main */
        $main = Product::factory()->for($store)->inCategory($category)->create([
            'sku'              => 'SKU-MAIN',
            'price'            => 300,
            'quantity'         => 10,
            'quantity_step'    => 1,
            'measurement_unit' => 'piece',
            'stock_mode'       => 'ignore',
        ]);

        /** @var Product $sauce */
        $sauce = Product::factory()->for($store)->create([
            'sku'           => 'SKU-SAUCE',
            'price'         => 50,
            'quantity'      => 20,
            'quantity_step' => 1,
            'stock_mode'    => 'ignore',
        ]);

        // Добавка из номенклатуры
        $supplement = Supplement::create([
            'product_id' => $main->id,
            'title'      => 'Соус',
            'type'       => 'many',
        ]);

        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Сырный соус',
            'price'         => null,
            'product_id'    => $sauce->id,
            'max_quantity'  => 5,
        ]);

        // Корзина: root-позиция + дочерняя (1 добавка × 1 корень)
        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        $rootCartItem = $cart->items()->create([
            'product_id' => $main->id,
            'store_id'   => $store->id,
            'quantity'   => 1,
            'price'      => 300,
        ]);

        $childCartItem = $cart->items()->create([
            'product_id'                 => $sauce->id,
            'store_id'                   => $store->id,
            'quantity'                   => 1,
            'price'                      => (float) $sauce->price,
            'parent_id'                  => $rootCartItem->id,
            'source_supplement_value_id' => $value->id,
        ]);

        // --- Оформление заказа ---
        app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));

        // --- Проверки ---
        $order = Order::latest('id')->firstOrFail();

        // Должно быть ровно 2 order_items: root и child
        $this->assertCount(2, $order->items);

        $rootItem = $order->items()->whereNull('parent_id')->firstOrFail();
        $childItem = $order->items()->whereNotNull('parent_id')->firstOrFail();

        // Связь parent_id
        $this->assertSame($rootItem->id, $childItem->parent_id);

        // Источник добавки зафиксирован
        $this->assertNotNull($childItem->source_supplement_value_id);
        $this->assertSame($value->id, $childItem->source_supplement_value_id);

        // Продукт в дочернем item = соус
        $this->assertSame($sauce->id, $childItem->product_id);

        // Снапшот sku/title/price
        $this->assertSame($sauce->sku, $childItem->sku);
        $this->assertSame((float) $sauce->price, (float) $childItem->price);
    }

    public function test_order_subtotal_includes_catalog_supplement(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount'   => null,
            'delivery_price'     => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();

        $main = Product::factory()->for($store)->inCategory($category)->create([
            'price'            => 200,
            'quantity'         => 10,
            'quantity_step'    => 1,
            'measurement_unit' => 'piece',
            'stock_mode'       => 'ignore',
        ]);

        $sauce = Product::factory()->for($store)->create([
            'price'         => 80,
            'quantity'      => 10,
            'quantity_step' => 1,
            'stock_mode'    => 'ignore',
        ]);

        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Топпинг', 'type' => 'many']);
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Сыр',
            'price'         => null,
            'product_id'    => $sauce->id,
            'max_quantity'  => 3,
        ]);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        $rootCartItem = $cart->items()->create([
            'product_id' => $main->id,
            'store_id'   => $store->id,
            'quantity'   => 2,    // 2 порции
            'price'      => 200,
        ]);

        // 1 добавка × 2 порции → quantity=2
        $cart->items()->create([
            'product_id'                 => $sauce->id,
            'store_id'                   => $store->id,
            'quantity'                   => 2,
            'price'                      => 80.0,
            'parent_id'                  => $rootCartItem->id,
            'source_supplement_value_id' => $value->id,
        ]);

        app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));

        $order = Order::latest('id')->firstOrFail();

        // subtotal = main(200×2=400) + sauce(80×2=160) = 560
        // calculateSubtotal() должен учитывать child cart_items
        $this->assertSame(560.0, (float) $order->subtotal);
    }

    public function test_outbound_payload_links_child_item_to_parent(): void
    {
        // --- Подготовка (как в test_order_creates_child_item_for_catalog_supplement) ---
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount'   => null,
            'delivery_price'     => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();

        $main = Product::factory()->for($store)->inCategory($category)->create([
            'sku'              => 'SKU-MAIN',
            'price'            => 300,
            'quantity'         => 10,
            'quantity_step'    => 1,
            'measurement_unit' => 'piece',
            'stock_mode'       => 'ignore',
        ]);

        $sauce = Product::factory()->for($store)->create([
            'sku'           => 'SKU-SAUCE',
            'price'         => 50,
            'quantity'      => 20,
            'quantity_step' => 1,
            'stock_mode'    => 'ignore',
        ]);

        $supplement = Supplement::create([
            'product_id' => $main->id,
            'title'      => 'Соус',
            'type'       => 'many',
        ]);

        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Сырный соус',
            'price'         => null,
            'product_id'    => $sauce->id,
            'max_quantity'  => 5,
        ]);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        $rootCartItem = $cart->items()->create([
            'product_id' => $main->id,
            'store_id'   => $store->id,
            'quantity'   => 1,
            'price'      => 300,
        ]);

        $cart->items()->create([
            'product_id'                 => $sauce->id,
            'store_id'                   => $store->id,
            'quantity'                   => 1,
            'price'                      => (float) $sauce->price,
            'parent_id'                  => $rootCartItem->id,
            'source_supplement_value_id' => $value->id,
        ]);

        app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));

        $order = Order::latest('id')->firstOrFail();
        $rootItem = $order->items()->whereNull('parent_id')->firstOrFail();
        $childItem = $order->items()->whereNotNull('parent_id')->firstOrFail();

        // --- Построить payload через трансформер ---
        $payload = app(OrderEventTransformer::class)->toEvent($order);

        $this->assertArrayHasKey('items', $payload);
        $this->assertCount(2, $payload['items']);

        // Найти root и child item в payload по line_id
        $byLineId = collect($payload['items'])->keyBy('line_id');

        $rootPayload = $byLineId->get((string) $rootItem->id);
        $childPayload = $byLineId->get((string) $childItem->id);

        $this->assertNotNull($rootPayload, 'root item должен присутствовать в payload');
        $this->assertNotNull($childPayload, 'child item должен присутствовать в payload');

        // Root: line_id есть, parent_line_id == null
        $this->assertSame((string) $rootItem->id, $rootPayload['line_id']);
        $this->assertNull($rootPayload['parent_line_id']);
        $this->assertNull($rootPayload['source_supplement_value_external']);

        // Child: parent_line_id == line_id родителя, source указан
        $this->assertSame((string) $childItem->id, $childPayload['line_id']);
        $this->assertSame((string) $rootItem->id, $childPayload['parent_line_id']);
        $this->assertSame((string) $value->id, $childPayload['source_supplement_value_external']);
    }

    /**
     * Fix #1: items_count не учитывает дочерние позиции добавок.
     * 1 product + 1 catalog supplement → items_count == 1; subtotal == 380 (300+80).
     */
    public function test_items_count_excludes_child_supplement_positions(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount'   => null,
            'delivery_price'     => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();

        $main = Product::factory()->for($store)->inCategory($category)->create([
            'price'         => 300,
            'quantity'      => 10,
            'quantity_step' => 1,
            'stock_mode'    => 'ignore',
        ]);

        $sauce = Product::factory()->for($store)->create([
            'price'         => 80,
            'quantity'      => 10,
            'quantity_step' => 1,
            'stock_mode'    => 'ignore',
        ]);

        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many']);
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Сырный',
            'price'         => null,
            'product_id'    => $sauce->id,
            'max_quantity'  => 3,
        ]);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        $rootCartItem = $cart->items()->create([
            'product_id' => $main->id,
            'store_id'   => $store->id,
            'quantity'   => 1,
            'price'      => 300,
        ]);

        $cart->items()->create([
            'product_id'                 => $sauce->id,
            'store_id'                   => $store->id,
            'quantity'                   => 1,
            'price'                      => 80.0,
            'parent_id'                  => $rootCartItem->id,
            'source_supplement_value_id' => $value->id,
        ]);

        app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));

        $order = Order::latest('id')->firstOrFail();

        // Клиент видит 1 позицию (корневой товар), а не 2 (root + child добавки)
        $this->assertSame(1, (int) $order->items_count,
            'items_count должен считать только корневые позиции, а не дочерние добавки');

        // Subtotal при этом включает добавку: 300×1 + 80×1 = 380
        $this->assertSame(380.0, (float) $order->subtotal,
            'subtotal должен включать стоимость товарной добавки');
    }

    /**
     * Fix #2: track_stock=false на SupplementValue переопределяет учёт остатка.
     * Дочерняя позиция с track_stock=false НЕ декрементирует quantity при резервировании.
     */
    public function test_track_stock_false_supplement_not_decremented_on_reserve(): void
    {
        $sauce = Product::factory()->create([
            'stock_mode'    => 'fixed',   // fixed — обычно декрементируется
            'quantity'      => 10,
            'quantity_step' => 1,
        ]);

        $main = Product::factory()->create([
            'stock_mode'    => 'ignore',
            'quantity'      => 0,
            'quantity_step' => 1,
        ]);

        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many']);

        // track_stock=false: override запрещает учёт остатка этой добавки
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Бесплатный соус',
            'price'         => 0,
            'product_id'    => $sauce->id,
            'max_quantity'  => 5,
            'track_stock'   => false,
        ]);

        $cart = \App\Models\Cart::factory()->create();

        $rootCartItem = $cart->items()->create([
            'product_id' => $main->id,
            'store_id'   => $main->store_id,
            'quantity'   => 1,
            'price'      => 0,
        ]);

        $childCartItem = $cart->items()->create([
            'product_id'                 => $sauce->id,
            'store_id'                   => $sauce->store_id,
            'quantity'                   => 3,
            'price'                      => 0.0,
            'parent_id'                  => $rootCartItem->id,
            'source_supplement_value_id' => $value->id,
        ]);

        $items = collect([$rootCartItem, $childCartItem]);

        app(\App\Actions\Order\ReserveProductsAction::class)->execute($items);

        // stock_mode=fixed у соуса, но track_stock=false на SupplementValue → quantity НЕ декрементирован
        $this->assertSame(10, (int) $sauce->fresh()->quantity,
            'quantity соуса не должен уменьшиться при track_stock=false на SupplementValue');
    }

    public function test_virtual_supplements_still_work_after_catalog_supplement(): void
    {
        // Гарантируем, что виртуальные OrderItemSupplement не сломаны
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount'   => null,
            'delivery_price'     => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();

        $main = Product::factory()->for($store)->inCategory($category)->create([
            'price'         => 300,
            'quantity'      => 10,
            'quantity_step' => 1,
            'stock_mode'    => 'ignore',
        ]);

        // Виртуальная добавка (без product_id)
        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Острота', 'type' => 'one']);
        $virtValue = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Острый',
            'price'         => 30,
        ]);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        $rootCartItem = $cart->items()->create([
            'product_id' => $main->id,
            'store_id'   => $store->id,
            'quantity'   => 1,
            'price'      => 300,
        ]);

        // Виртуальная добавка через CartItemSupplement
        $cartItemSupplement = $rootCartItem->supplements()->create([
            'supplement_id' => $supplement->id,
        ]);
        $cartItemSupplement->values()->attach($virtValue->id);

        // Reload с relations как делает CreateOrderAction
        $rootCartItem->load(['supplements.values']);

        app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));

        $order = Order::latest('id')->firstOrFail();

        // Только 1 root order_item (без дочерних)
        $this->assertCount(1, $order->items);
        $rootItem = $order->items()->whereNull('parent_id')->firstOrFail();

        // Виртуальная добавка скопирована в OrderItemSupplement
        $this->assertCount(1, $rootItem->supplements);
        $this->assertSame($supplement->id, $rootItem->supplements->first()->supplement_id);
    }
}
