<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Product;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogSupplementTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplement_value_can_reference_catalog_product(): void
    {
        $sauce = Product::factory()->create(['stock_mode' => 'normal', 'quantity' => 10]);
        $main = Product::factory()->create();
        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many']);

        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title' => 'Сырный соус',
            'price' => null,
            'product_id' => $sauce->id,
            'max_quantity' => 3,
            'position' => 1,
        ]);

        $this->assertTrue($value->isFromCatalog());
        $this->assertSame($sauce->id, $value->product->id);
        $this->assertSame(3, $value->max_quantity);
        $this->assertTrue($value->tracksStock());
    }

    public function test_virtual_supplement_value_is_not_from_catalog(): void
    {
        $main = Product::factory()->create();
        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Острота', 'type' => 'one']);
        $value = SupplementValue::create(['supplement_id' => $supplement->id, 'title' => 'Острый', 'price' => 50]);

        $this->assertFalse($value->isFromCatalog());
        $this->assertFalse($value->tracksStock());
        $this->assertSame(50.0, $value->effectivePrice());
    }

    public function test_cart_item_can_have_parent_child_supplement(): void
    {
        $user = \App\Models\User::factory()->create();
        $cart = \App\Models\Cart::create(['user_id' => $user->id]);
        $main = Product::factory()->create();
        $sauce = Product::factory()->create();

        $parent = $cart->items()->create([
            'product_id' => $main->id, 'store_id' => $main->store_id,
            'quantity' => 2, 'price' => 100,
        ]);
        $child = $cart->items()->create([
            'product_id' => $sauce->id, 'store_id' => $sauce->store_id,
            'quantity' => 2, 'price' => 50,
            'parent_id' => $parent->id,
        ]);

        $this->assertSame($parent->id, $child->parent->id);
        $this->assertTrue($parent->childItems->contains($child));
        $this->assertCount(1, \App\Models\CartItem::roots()->where('cart_id', $cart->id)->get());
    }

    public function test_order_item_can_have_parent_child(): void
    {
        $order = \App\Models\Order::factory()->create();
        $mainProduct = Product::factory()->create();
        $sauceProduct = Product::factory()->create();

        $parent = \App\Models\OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $mainProduct->id,
            'title'      => $mainProduct->title ?? 'Main',
            'sku'        => 'SKU-MAIN-001',
            'price'      => 200.0,
            'quantity'   => 1,
            'total'      => 200.0,
            'created_at' => now(),
        ]);

        $child = \App\Models\OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $sauceProduct->id,
            'title'      => $sauceProduct->title ?? 'Sauce',
            'sku'        => 'SKU-SAUCE-001',
            'price'      => 50.0,
            'quantity'   => 1,
            'total'      => 50.0,
            'parent_id'  => $parent->id,
            'created_at' => now(),
        ]);

        $this->assertSame($parent->id, $child->parent->id);
        $this->assertTrue($parent->childItems->contains($child));
        $this->assertCount(1, \App\Models\OrderItem::roots()->where('order_id', $order->id)->get());
    }

    public function test_cart_rejects_quantity_above_max(): void
    {
        $user = User::factory()->create();
        $main = Product::factory()->create(['quantity_step' => 1]);
        $sauce = Product::factory()->create(['stock_mode' => 'ignore']);
        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many']);
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id, 'title' => 'Сырный', 'price' => null,
            'product_id' => $sauce->id, 'max_quantity' => 3,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $main->id,
            'quantity' => 1,
            'supplements' => [[
                'supplementId' => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 5]],
            ]],
        ]);

        $response->assertStatus(422);
    }

    public function test_catalog_supplement_creates_child_cart_item(): void
    {
        $user = User::factory()->create();
        $main = Product::factory()->create(['quantity_step' => 1, 'stock_mode' => 'ignore']);
        $sauce = Product::factory()->create(['stock_mode' => 'ignore']);
        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many']);
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id, 'title' => 'Сырный', 'price' => 70,
            'product_id' => $sauce->id, 'max_quantity' => 3,
        ]);

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $main->id,
            'quantity' => 2,
            'supplements' => [[
                'supplementId' => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 1]],
            ]],
        ])->assertOk();

        $root = \App\Models\CartItem::roots()->where('product_id', $main->id)->firstOrFail();
        $child = \App\Models\CartItem::where('parent_id', $root->id)->firstOrFail();

        $this->assertSame($sauce->id, $child->product_id);
        $this->assertSame($value->id, $child->source_supplement_value_id);
        $this->assertSame(2, $child->quantity);             // 1 на единицу × 2 порции
        $this->assertSame(70.0, (float) $child->price);
    }

    public function test_catalog_supplement_quantity_scales_by_weighted_portions(): void
    {
        $user = User::factory()->create();

        // Весовой товар: шаг 200 г, quantity=400 → 2 порции
        $main = Product::factory()->create([
            'quantity_step' => 200,
            'stock_mode'    => 'ignore',
        ]);

        // Товар-добавка без ограничений по остатку
        $sauce = Product::factory()->create(['stock_mode' => 'ignore']);

        $supplement = Supplement::create([
            'product_id' => $main->id,
            'title'      => 'Соус',
            'type'       => 'many',
        ]);

        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Сырный',
            'price'         => 50,
            'product_id'    => $sauce->id,
            'max_quantity'  => 10,
        ]);

        // 400 г основного = 2 порции по 200 г; 1 соус на порцию → ожидаем qty=2 у дочерней позиции
        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId'   => $main->id,
            'quantity'    => 400,
            'supplements' => [[
                'supplementId'    => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 1]],
            ]],
        ])->assertOk();

        $root  = \App\Models\CartItem::roots()->where('product_id', $main->id)->firstOrFail();
        $child = \App\Models\CartItem::where('parent_id', $root->id)->firstOrFail();

        // 1 (на единицу) × 2 (порции) = 2, НЕ 400
        $this->assertSame(2, $child->quantity);
        $this->assertSame($sauce->id, $child->product_id);
    }

    public function test_same_catalog_supplement_merges_and_rescales_child(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $main = Product::factory()->create(['quantity_step' => 1, 'stock_mode' => 'ignore']);
        $sauce = Product::factory()->create(['stock_mode' => 'ignore']);
        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many']);
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id, 'title' => 'Сырный', 'price' => 70,
            'product_id' => $sauce->id, 'max_quantity' => 5,
        ]);
        $payload = [
            'productId' => $main->id, 'quantity' => 2,
            'supplements' => [['supplementId' => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 1]]]],
        ];

        $this->postJson('/api/v1/cart/items', $payload)->assertOk();
        $this->postJson('/api/v1/cart/items', $payload)->assertOk();   // тот же набор → мерж

        $roots = \App\Models\CartItem::roots()->where('product_id', $main->id)->get();
        $this->assertCount(1, $roots);                       // позиции слились
        $this->assertSame(4, (int) $roots->first()->quantity);
        $child = \App\Models\CartItem::where('parent_id', $roots->first()->id)->firstOrFail();
        $this->assertSame(4, (int) $child->quantity);        // 1 на единицу × 4 порции
    }

    public function test_cart_rejects_insufficient_catalog_supplement_stock(): void
    {
        // Товар-добавка: normal stock, quantity=2 (доступно 2 единицы)
        $sauce = Product::factory()->create([
            'stock_mode' => 'normal',
            'quantity'   => 2,
            'quantity_step' => 1,
        ]);

        // Основной товар: игнорируем его остаток, шаг=1
        $main = Product::factory()->create([
            'quantity_step' => 1,
            'stock_mode'    => 'ignore',
        ]);

        $supplement = Supplement::create([
            'product_id' => $main->id,
            'title'      => 'Соус',
            'type'       => 'many',
        ]);

        // max_quantity=10, чтобы не срабатывал раньше проверки остатка
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Сырный',
            'price'         => null,
            'product_id'    => $sauce->id,
            'max_quantity'  => 10,
        ]);

        $user = User::factory()->create();

        // 2 порции основного × 2 соуса = нужно 4, в наличии 2 → 422
        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $main->id,
            'quantity'  => 2,
            'supplements' => [[
                'supplementId'   => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 2]],
            ]],
        ]);

        $response->assertStatus(422);

        // 1 порция основного × 1 соус = нужно 1, в наличии 2 → OK
        $okResponse = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $main->id,
            'quantity'  => 1,
            'supplements' => [[
                'supplementId'   => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 1]],
            ]],
        ]);

        $okResponse->assertOk();
    }

    public function test_merge_rejects_when_catalog_supplement_stock_insufficient(): void
    {
        // Товар-добавка: normal stock, quantity=3
        $sauce = Product::factory()->create([
            'stock_mode'    => 'normal',
            'quantity'      => 3,
            'quantity_step' => 1,
        ]);

        // Основной товар: игнорируем его остаток
        $main = Product::factory()->create([
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
            'title'         => 'Сырный',
            'price'         => null,
            'product_id'    => $sauce->id,
            'max_quantity'  => 10,
        ]);

        $user = User::factory()->create();
        $payload = [
            'productId'   => $main->id,
            'quantity'    => 2,
            'supplements' => [[
                'supplementId'    => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 1]],
            ]],
        ];

        // Первый запрос: 1×2=2 соуса ≤ 3 в наличии → OK
        $this->actingAs($user)->postJson('/api/v1/cart/items', $payload)->assertOk();

        // Второй запрос (мерж): 1×(2+2)=4 соуса > 3 в наличии → 422
        $this->actingAs($user)->postJson('/api/v1/cart/items', $payload)->assertStatus(422);
    }

    public function test_update_root_quantity_rescales_child_supplement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $main = Product::factory()->create(['quantity_step' => 1, 'stock_mode' => 'ignore']);
        $sauce = Product::factory()->create(['stock_mode' => 'ignore']);
        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many']);
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id, 'title' => 'Сырный', 'price' => 70,
            'product_id' => $sauce->id, 'max_quantity' => 5,
        ]);
        $this->postJson('/api/v1/cart/items', [
            'productId' => $main->id, 'quantity' => 1,
            'supplements' => [['supplementId' => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 2]]]],
        ])->assertOk();
        $root = \App\Models\CartItem::roots()->where('product_id', $main->id)->firstOrFail();

        $this->putJson("/api/v1/cart/items/{$root->id}", ['quantity' => 3])->assertOk();

        $child = \App\Models\CartItem::where('parent_id', $root->id)->firstOrFail();
        $this->assertSame(6, (int) $child->quantity);        // 2 на единицу × 3 порции
    }

    public function test_cart_response_folds_child_into_supplements(): void
    {
        $user = \App\Models\User::factory()->create();
        $main = Product::factory()->create(['quantity_step' => 1, 'stock_mode' => 'ignore']);
        $sauce = Product::factory()->create(['stock_mode' => 'normal', 'quantity' => 9]);
        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many']);
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id, 'title' => 'Сырный', 'price' => 70,
            'product_id' => $sauce->id, 'max_quantity' => 3,
        ]);
        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $main->id, 'quantity' => 2,
            'supplements' => [['supplementId' => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 1]]]],
        ])->assertOk();

        $res = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();
        $items = collect($res['storeGroups'][0]['items'] ?? $res['items'] ?? []);
        $this->assertCount(1, $items);                                   // child не на верхнем уровне
        $sup = $items[0]['supplements'][0]['values'][0];
        $this->assertTrue($sup['isFromCatalog']);
        $this->assertSame($sauce->id, $sup['productId']);
        $this->assertSame(9, $sup['stockQuantity']);
        $this->assertSame(2, $sup['quantity']);                          // 1 × 2 порции
    }

    public function test_update_rejects_when_catalog_supplement_stock_insufficient(): void
    {
        // Товар-добавка: normal stock, quantity=3
        $sauce = Product::factory()->create([
            'stock_mode'    => 'normal',
            'quantity'      => 3,
            'quantity_step' => 1,
        ]);

        // Основной товар: игнорируем его остаток, шаг=1
        $main = Product::factory()->create([
            'quantity_step' => 1,
            'stock_mode'    => 'ignore',
        ]);

        $supplement = Supplement::create([
            'product_id' => $main->id,
            'title'      => 'Соус',
            'type'       => 'many',
        ]);

        // max_quantity=10, чтобы не срабатывал раньше проверки остатка
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Сырный',
            'price'         => null,
            'product_id'    => $sauce->id,
            'max_quantity'  => 10,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        // Первый POST: quantity=2, valueQuantities=[{1}] → 1 соус × 2 порции = 2 соуса ≤ 3 в наличии → OK
        $postResponse = $this->postJson('/api/v1/cart/items', [
            'productId'   => $main->id,
            'quantity'    => 2,
            'supplements' => [[
                'supplementId'    => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 1]],
            ]],
        ]);
        $postResponse->assertOk();

        // Получить root cart_item id
        $root = \App\Models\CartItem::roots()->where('product_id', $main->id)->firstOrFail();

        // PUT quantity=4 → 1 соус × 4 порции = 4 соуса > 3 в наличии → 422
        $this->putJson("/api/v1/cart/items/{$root->id}", ['quantity' => 4])
            ->assertStatus(422);
    }

    /**
     * Тест 1: Товарная добавка (дочерний cart_item) учитывается в subtotal
     * для порога бесплатной доставки в CartCalculator.
     *
     * Lock-тест: CartCalculator::calculateSubtotalWithSupplements включает
     * childItems::sum(price × portions) наравне с виртуальными добавками.
     */
    public function test_catalog_supplement_child_item_counted_in_delivery_threshold(): void
    {
        $store = \App\Models\Store::factory()->create([
            'free_delivery_from' => 500,
            'delivery_price'     => 100,
        ]);
        $user = User::factory()->create();

        $main  = Product::factory()->for($store)->create([
            'price'         => 300,
            'quantity'      => 50,
            'quantity_step' => 1,
            'stock_mode'    => 'ignore',
        ]);
        $sauce = Product::factory()->for($store)->create([
            'price'         => 250,
            'quantity'      => 50,
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
            'title'         => 'Сырный',
            'price'         => 250,
            'product_id'    => $sauce->id,
            'max_quantity'  => 5,
        ]);

        // 1 порция основного (300) + 1 добавка (250) = 550 ≥ 500 → доставка бесплатная
        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId'   => $main->id,
            'quantity'    => 1,
            'supplements' => [[
                'supplementId'    => $supplement->id,
                'valueQuantities' => [['valueId' => $value->id, 'quantity' => 1]],
            ]],
        ])->assertOk();

        $res = $this->actingAs($user)->getJson('/api/v1/cart?shippingMethod=courier')->assertOk()->json();

        // Стоимость доставки должна быть 0 (порог достигнут с учётом добавки)
        $deliveryCost = $res['storeGroups'][0]['deliveryCost'];
        $this->assertSame(0.0, (float) $deliveryCost,
            'Товарная добавка должна учитываться в subtotal для порога бесплатной доставки');
    }

    public function test_out_of_stock_catalog_supplement_hidden_in_product_card(): void
    {
        $main = Product::factory()->create(['stock_mode' => 'ignore']);
        $sauce = Product::factory()->create(['stock_mode' => 'normal', 'quantity' => 0, 'quantity_step' => 1]);
        $supplement = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many']);
        SupplementValue::create([
            'supplement_id' => $supplement->id, 'title' => 'Нет в наличии',
            'price' => null, 'product_id' => $sauce->id,
        ]);

        $res = $this->getJson("/api/v1/products/{$main->id}")->assertOk()->json();
        // ProductResource оборачивает в data
        $values = collect($res['data']['supplements'][0]['values'] ?? []);
        $this->assertCount(0, $values);
    }

    /**
     * Тест 2: Lock-тест аддитивности виртуальных добавок.
     *
     * Виртуальная добавка (product_id=null) в ответе /api/v1/cart
     * должна содержать ровно ключи id, title, price
     * и НЕ содержать каталожные поля isFromCatalog/productId/stockQuantity/
     * maxQuantity/imageUrl/quantity.
     */
    public function test_virtual_supplement_value_serializes_without_catalog_fields(): void
    {
        $user = User::factory()->create();
        $store = \App\Models\Store::factory()->create();
        $category = \App\Models\Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price'    => 200,
            'quantity' => 10,
        ]);

        $supplement = Supplement::create([
            'product_id' => $product->id,
            'title'      => 'Острота',
            'type'       => 'one',
        ]);

        // Виртуальная добавка: product_id не задан
        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title'         => 'Средняя',
            'price'         => 30,
            // product_id намеренно не задан — это виртуальная добавка
        ]);

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId'   => $product->id,
            'quantity'    => 1,
            'supplements' => [[
                'supplementId' => $supplement->id,
                'valueIds'     => [$value->id],
            ]],
        ])->assertOk();

        $res = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();
        $items = $res['storeGroups'][0]['items'];
        $this->assertCount(1, $items, 'Дочерние позиции не должны появляться на верхнем уровне');

        $supplementGroups = $items[0]['supplements'];
        $this->assertNotEmpty($supplementGroups, 'Виртуальная добавка должна присутствовать в supplements');

        $valueData = $supplementGroups[0]['values'][0];

        // Обязательные ключи виртуальной добавки
        $this->assertArrayHasKey('id', $valueData);
        $this->assertArrayHasKey('title', $valueData);
        $this->assertArrayHasKey('price', $valueData);

        // Каталожные ключи должны отсутствовать для виртуальной добавки
        $catalogFields = ['isFromCatalog', 'productId', 'stockQuantity', 'maxQuantity', 'imageUrl', 'quantity'];
        foreach ($catalogFields as $field) {
            $this->assertArrayNotHasKey($field, $valueData,
                "Виртуальная добавка не должна содержать поле «{$field}»");
        }
    }
}
