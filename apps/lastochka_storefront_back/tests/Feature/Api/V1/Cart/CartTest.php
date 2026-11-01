<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Cart;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Get Cart ====================

    public function test_get_empty_cart(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJson(['storeGroups' => []]);
    }

    public function test_get_cart_with_items(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 2,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.id', $store->id)
            ->assertJsonPath('storeGroups.0.items.0.quantity', 2)
            ->assertJsonPath('storeGroups.0.items.0.price', 100);
    }

    public function test_get_cart_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/cart');

        $response->assertUnauthorized();
    }

    // ==================== Add to Cart ====================

    public function test_add_to_cart(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 150,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.items.0.quantity', 2)
            ->assertJsonPath('storeGroups.0.items.0.price', 150);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 150,
        ]);
    }

    public function test_add_to_cart_increases_quantity_if_exists(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 20,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 3,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.items.0.quantity', 5);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 5,
        ]);
    }

    public function test_add_to_cart_fails_if_insufficient_stock(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 5,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 10,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', fn ($msg) => str_contains($msg, 'недоступен'));
    }

    // ==================== Update Quantity ====================

    public function test_update_quantity(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 20,
        ]);

        $cart = Cart::factory()->for($user)->create();
        $cartItem = CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 2,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->putJson("/api/v1/cart/items/{$cartItem->id}", [
            'quantity' => 5,
        ]);

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.items.0.quantity', 5);
    }

    public function test_update_quantity_fails_for_other_user_cart(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create();

        $cart = Cart::factory()->for($otherUser)->create();
        $cartItem = CartItem::factory()->for($cart)->for($product)->for($store)->create();

        $response = $this->actingAs($user)->putJson("/api/v1/cart/items/{$cartItem->id}", [
            'quantity' => 5,
        ]);

        $response->assertForbidden();
    }

    // ==================== Remove Item ====================

    public function test_remove_item(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create();

        $cart = Cart::factory()->for($user)->create();
        $cartItem = CartItem::factory()->for($cart)->for($product)->for($store)->create();

        $response = $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$cartItem->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('cart_items', ['id' => $cartItem->id]);
    }

    // ==================== Clear Cart ====================

    public function test_clear_cart(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create();

        $response = $this->actingAs($user)->deleteJson('/api/v1/cart');

        $response->assertNoContent();

        $this->assertDatabaseCount('cart_items', 0);
    }

    // ==================== Apply Promocode ====================

    public function test_apply_promocode(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);
        $promocode = Promocode::factory()->percent(10)->create([
            'code' => 'DISCOUNT10',
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 2,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/promocode', [
            'storeId' => $store->id,
            'promocode' => 'DISCOUNT10',
        ]);

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.promocode.code', 'DISCOUNT10')
            ->assertJsonPath('storeGroups.0.discount', 20);

        $this->assertDatabaseHas('cart_promocodes', [
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);
    }

    public function test_apply_invalid_promocode(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/promocode', [
            'storeId' => $store->id,
            'promocode' => 'INVALID',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.promocode.0', 'Промокод не найден.');
    }

    public function test_apply_expired_promocode(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create();
        Promocode::factory()->expired()->create([
            'code' => 'EXPIRED',
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/promocode', [
            'storeId' => $store->id,
            'promocode' => 'EXPIRED',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.promocode.0', 'Промокод недействителен или истёк.');
    }

    // ==================== Remove Promocode ====================

    public function test_remove_promocode(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create();
        $promocode = Promocode::factory()->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create();
        $cart->promocodes()->create([
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->deleteJson('/api/v1/cart/promocode', [
            'storeId' => $store->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.promocode', null);

        $this->assertDatabaseMissing('cart_promocodes', [
            'cart_id' => $cart->id,
            'store_id' => $store->id,
        ]);
    }

    // ==================== Clear Store Group ====================

    public function test_clear_store_group(): void
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();
        $category1 = Category::factory()->forStore($store1)->create();
        $category2 = Category::factory()->forStore($store2)->create();
        $product1 = Product::factory()->for($store1)->inCategory($category1)->create();
        $product2 = Product::factory()->for($store2)->inCategory($category2)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product1)->for($store1)->create();
        CartItem::factory()->for($cart)->for($product2)->for($store2)->create();

        $response = $this->actingAs($user)->deleteJson("/api/v1/cart/stores/{$store1->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('cart_items', ['store_id' => $store1->id]);
        $this->assertDatabaseHas('cart_items', ['store_id' => $store2->id]);
    }

    // ==================== Groups By Store ====================

    public function test_groups_by_store(): void
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();
        $category1 = Category::factory()->forStore($store1)->create();
        $category2 = Category::factory()->forStore($store2)->create();
        $product1 = Product::factory()->for($store1)->inCategory($category1)->create(['price' => 100, 'quantity' => 10]);
        $product2 = Product::factory()->for($store2)->inCategory($category2)->create(['price' => 200, 'quantity' => 10]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product1)->for($store1)->create(['price' => 100, 'quantity' => 1]);
        CartItem::factory()->for($cart)->for($product2)->for($store2)->create(['price' => 200, 'quantity' => 1]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonCount(2, 'storeGroups');
    }

    // ==================== Discount Calculation ====================

    public function test_discount_calculation_percent(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 1000,
            'quantity' => 10,
        ]);
        $promocode = Promocode::factory()->percent(15)->create(['code' => 'P15']);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 2,
            'price' => 1000,
        ]);
        $cart->promocodes()->create([
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.discount', 300);
    }

    public function test_discount_not_applied_to_discounted_products(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->withDiscount()->create([
            'price' => 80,
            'price_old' => 100,
            'quantity' => 10,
        ]);
        $promocode = Promocode::factory()->percent(10)->create(['code' => 'P10']);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 1,
            'price' => 80,
        ]);
        $cart->promocodes()->create([
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.discount', 0);
    }

    // ==================== Delivery Cost Calculation ====================

    public function test_delivery_cost_calculation(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'delivery_price' => 200,
            'free_delivery_from' => 1000,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 2,
            'price' => 300,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 200);
    }

    public function test_free_delivery_when_threshold_reached(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'delivery_price' => 200,
            'free_delivery_from' => 1000,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 600,
            'quantity' => 10,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 2,
            'price' => 600,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0);
    }

    public function test_free_delivery_threshold_from_slots_api_when_address_active(): void
    {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $store = Store::factory()->create([
            'code' => 'shop-threshold',
            'delivery_price' => 100,
            'free_delivery_from' => null,
        ]);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            [
                'id' => 1,
                'code' => 'shop-threshold',
                'delivery_price' => 100,
                'min_order_price' => 550,
                'free_delivery_from' => 800,
            ],
        ]);

        $category = Category::factory()->forStore($store)->create();
        $banana = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 150,
            'quantity' => 100,
        ]);
        $lemon = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 50,
            'quantity' => 100,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($banana)->for($store)->create([
            'quantity' => 5,
            'price' => 150,
        ]);
        CartItem::factory()->for($cart)->for($lemon)->for($store)->create([
            'quantity' => 1,
            'price' => 50,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0)
            ->assertJsonPath('storeGroups.0.freeDeliveryFrom', 800);
    }

    public function test_delivery_paid_when_subtotal_below_slots_api_threshold(): void
    {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $store = Store::factory()->create([
            'code' => 'shop-below',
            'delivery_price' => 100,
            'free_delivery_from' => null,
        ]);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            [
                'id' => 1,
                'code' => 'shop-below',
                'delivery_price' => 100,
                'min_order_price' => 100,
                'free_delivery_from' => 800,
            ],
        ]);

        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 150,
            'quantity' => 100,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 1,
            'price' => 150,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 100)
            ->assertJsonPath('storeGroups.0.freeDeliveryFrom', 800);
    }

    public function test_slots_api_threshold_overrides_store_free_delivery_from(): void
    {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        // В Store дефолт 1500, но для зоны из slots-api порог 800.
        // При subtotal=900 ожидаем бесплатную доставку (приоритет slots-api).
        $store = Store::factory()->create([
            'code' => 'shop-override',
            'delivery_price' => 200,
            'free_delivery_from' => 1500,
        ]);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            [
                'id' => 1,
                'code' => 'shop-override',
                'delivery_price' => 100,
                'min_order_price' => 100,
                'free_delivery_from' => 800,
            ],
        ]);

        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 900,
            'quantity' => 100,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 1,
            'price' => 900,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0)
            ->assertJsonPath('storeGroups.0.freeDeliveryFrom', 800);
    }

    // ==================== Promotion Price in Cart ====================

    public function test_add_to_cart_with_promotion_uses_effective_price(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'price_old' => null,
            'quantity' => 10,
        ]);

        // Создаём активную акцию со скидкой 20%
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(20)
            ->create();
        $promotion->products()->attach($product);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.items.0.price', 80);  // 100 - 20% = 80

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 80,
        ]);
    }

    public function test_sync_prices_updates_to_promotion_price(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        // Товар добавлен в корзину по обычной цене
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 1,
            'price' => 100,
        ]);

        // Теперь товар попадает в акцию 30%
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(30)
            ->create();
        $promotion->products()->attach($product);

        // При получении корзины цена должна синхронизироваться
        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.items.0.price', 70);  // 100 - 30% = 70

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'price' => 70,
        ]);
    }

    public function test_expired_promotion_does_not_affect_cart_price(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        // Истёкшая акция
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(20)
            ->expired()
            ->create();
        $promotion->products()->attach($product);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.items.0.price', 100);  // Акция истекла — полная цена

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'price' => 100,
        ]);
    }
}
