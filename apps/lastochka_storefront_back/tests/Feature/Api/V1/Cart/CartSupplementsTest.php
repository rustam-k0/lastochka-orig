<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSupplement;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartSupplementsTest extends TestCase
{
    use RefreshDatabase;

    private function createProductWithSupplements(): array
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 170,
            'quantity' => 10,
        ]);

        $supplement1 = Supplement::create([
            'title' => 'Соус на выбор',
            'product_id' => $product->id,
            'type' => 'one',
            'is_required' => true,
        ]);

        $value1 = SupplementValue::create(['title' => 'Сырный', 'price' => 0, 'supplement_id' => $supplement1->id]);
        $value2 = SupplementValue::create(['title' => 'Кетчуп', 'price' => 0, 'supplement_id' => $supplement1->id]);

        $supplement2 = Supplement::create([
            'title' => 'Допы',
            'product_id' => $product->id,
            'type' => 'many',
            'is_required' => false,
        ]);

        $value3 = SupplementValue::create(['title' => 'Аджика', 'price' => 50, 'supplement_id' => $supplement2->id]);
        $value4 = SupplementValue::create(['title' => 'Сметана с чесноком', 'price' => 40, 'supplement_id' => $supplement2->id]);

        return compact('store', 'product', 'supplement1', 'supplement2', 'value1', 'value2', 'value3', 'value4');
    }

    public function test_add_to_cart_with_supplements(): void
    {
        $user = User::factory()->create();
        $data = $this->createProductWithSupplements();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 1,
            'supplements' => [
                ['supplementId' => $data['supplement1']->id, 'valueIds' => [$data['value1']->id]],
                ['supplementId' => $data['supplement2']->id, 'valueIds' => [$data['value3']->id, $data['value4']->id]],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $data['product']->id,
            'quantity' => 1,
        ]);

        $this->assertDatabaseHas('cart_item_supplements', [
            'supplement_id' => $data['supplement1']->id,
        ]);

        $this->assertDatabaseHas('cart_item_supplements', [
            'supplement_id' => $data['supplement2']->id,
        ]);
    }

    public function test_add_to_cart_fails_without_required_supplement(): void
    {
        $user = User::factory()->create();
        $data = $this->createProductWithSupplements();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 1,
        ]);

        $response->assertUnprocessable();
    }

    public function test_add_to_cart_fails_with_type_one_and_multiple_values(): void
    {
        $user = User::factory()->create();
        $data = $this->createProductWithSupplements();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 1,
            'supplements' => [
                // supplement1 is type=one, but we pass 2 values
                ['supplementId' => $data['supplement1']->id, 'valueIds' => [$data['value1']->id, $data['value2']->id]],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_add_to_cart_fails_with_supplement_not_belonging_to_product(): void
    {
        $user = User::factory()->create();
        $data = $this->createProductWithSupplements();

        // Создаём добавку для другого товара
        $otherProduct = Product::factory()->for($data['store'])->create(['quantity' => 10]);
        $otherSupplement = Supplement::create([
            'title' => 'Чужая добавка',
            'product_id' => $otherProduct->id,
            'type' => 'one',
            'is_required' => false,
        ]);
        $otherValue = SupplementValue::create(['title' => 'Чужая', 'price' => 10, 'supplement_id' => $otherSupplement->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 1,
            'supplements' => [
                ['supplementId' => $data['supplement1']->id, 'valueIds' => [$data['value1']->id]],
                ['supplementId' => $otherSupplement->id, 'valueIds' => [$otherValue->id]],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_supplements_total_calculated_correctly(): void
    {
        $user = User::factory()->create();
        $data = $this->createProductWithSupplements();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 2,
            'supplements' => [
                ['supplementId' => $data['supplement1']->id, 'valueIds' => [$data['value1']->id]],
                ['supplementId' => $data['supplement2']->id, 'valueIds' => [$data['value3']->id, $data['value4']->id]],
            ],
        ]);

        $response->assertOk();

        // supplementsTotal = (50 + 40) * 2 = 180
        $response->assertJsonPath('storeGroups.0.items.0.supplementsTotal', 180);
    }

    public function test_two_cart_items_for_same_product_with_different_supplements(): void
    {
        $user = User::factory()->create();
        $data = $this->createProductWithSupplements();

        // Первое добавление — с кетчупом
        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 1,
            'supplements' => [
                ['supplementId' => $data['supplement1']->id, 'valueIds' => [$data['value1']->id]],
            ],
        ]);

        // Второе добавление — с горчицей (другой набор добавок = новый CartItem)
        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 1,
            'supplements' => [
                ['supplementId' => $data['supplement1']->id, 'valueIds' => [$data['value2']->id]],
            ],
        ]);

        $response->assertOk();

        // Должно быть 2 отдельных CartItem
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_supplements_total_uses_portions_for_gram_products(): void
    {
        // ALADDIN-10348: для весовых товаров (step=500, quantity=500г = 1 порция)
        // supplementsTotal должен считаться от ПОРЦИЙ, а не от квантити в граммах.
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 22,
            'quantity' => 10000,
            'quantity_step' => 500,
            'measurement_unit' => 'gram',
        ]);

        $supplement = Supplement::create([
            'title' => 'Допы',
            'product_id' => $product->id,
            'type' => 'many',
            'is_required' => false,
        ]);
        $husk = SupplementValue::create(['title' => 'Шелуха', 'price' => 10, 'supplement_id' => $supplement->id]);
        $skin = SupplementValue::create(['title' => 'Кожа', 'price' => 20, 'supplement_id' => $supplement->id]);

        $cart = Cart::create(['user_id' => $user->id]);
        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 500,
            'price' => 22,
        ]);
        $cartItemSupplement = CartItemSupplement::create([
            'cart_item_id' => $cartItem->id,
            'supplement_id' => $supplement->id,
        ]);
        $cartItemSupplement->values()->attach([$husk->id, $skin->id]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart?shippingMethod=pickup');

        $response->assertOk();
        // 1 порция × (10 + 20) = 30, не 500 × 30 = 15000
        $response->assertJsonPath('storeGroups.0.items.0.supplementsTotal', 30);
    }

    public function test_add_to_cart_accepts_legacy_values_key(): void
    {
        // Совместимость для установленных версий Aladdin-мобилки, которые
        // сериализуют добавки как { "values": [...] } вместо канонического
        // "valueIds". prepareForValidation() должен принимать оба варианта.
        $user = User::factory()->create();
        $data = $this->createProductWithSupplements();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 1,
            'supplements' => [
                ['supplementId' => $data['supplement1']->id, 'values' => [$data['value1']->id]],
                ['supplementId' => $data['supplement2']->id, 'values' => [$data['value3']->id]],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('cart_item_supplements', [
            'supplement_id' => $data['supplement1']->id,
        ]);
        $this->assertDatabaseHas('cart_item_supplements', [
            'supplement_id' => $data['supplement2']->id,
        ]);
    }

    public function test_remove_item_response_keeps_supplements_for_remaining_items(): void
    {
        // ALADDIN-10606: после DELETE /cart/items/{id} response должен возвращать
        // оставшиеся items с supplementsTotal и массивом supplements — иначе фронт
        // показывает 0 до перезагрузки страницы.
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 225,
            'quantity' => 10,
        ]);

        $supplement = Supplement::create([
            'title' => 'Цвет',
            'product_id' => $product->id,
            'type' => 'many',
            'is_required' => false,
        ]);
        $val1 = SupplementValue::create(['title' => '20 DEN', 'price' => 1000, 'supplement_id' => $supplement->id]);
        $val2 = SupplementValue::create(['title' => '40 DEN', 'price' => 1200, 'supplement_id' => $supplement->id]);

        $cart = Cart::create(['user_id' => $user->id]);
        // Item 1 — остаётся
        $item1 = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 225,
        ]);
        CartItemSupplement::create(['cart_item_id' => $item1->id, 'supplement_id' => $supplement->id])
            ->values()->attach([$val1->id]);

        // Item 2 — удаляется
        $item2 = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 225,
        ]);
        CartItemSupplement::create(['cart_item_id' => $item2->id, 'supplement_id' => $supplement->id])
            ->values()->attach([$val2->id]);

        $response = $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$item2->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'storeGroups.0.items');
        $response->assertJsonPath('storeGroups.0.items.0.id', $item1->id);
        $response->assertJsonPath('storeGroups.0.items.0.supplementsTotal', 1000);
        $response->assertJsonCount(1, 'storeGroups.0.items.0.supplements');
    }

    public function test_same_product_with_same_supplements_increases_quantity(): void
    {
        $user = User::factory()->create();
        $data = $this->createProductWithSupplements();

        // Первое добавление
        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 1,
            'supplements' => [
                ['supplementId' => $data['supplement1']->id, 'valueIds' => [$data['value1']->id]],
            ],
        ]);

        // Второе добавление — тот же набор
        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $data['product']->id,
            'quantity' => 1,
            'supplements' => [
                ['supplementId' => $data['supplement1']->id, 'valueIds' => [$data['value1']->id]],
            ],
        ]);

        // Должен быть 1 CartItem с quantity=2
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $data['product']->id,
            'quantity' => 2,
        ]);
    }
}
