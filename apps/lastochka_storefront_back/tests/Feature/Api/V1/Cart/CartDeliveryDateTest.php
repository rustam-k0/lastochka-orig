<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CartDeliveryDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ==================== AddToCart with deliveryDate ====================

    public function test_add_to_cart_persists_delivery_date(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $tomorrow = now()->addDay()->format('Y-m-d');

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 2,
            'deliveryDate' => $tomorrow,
        ]);

        $response->assertOk();

        $cartItem = CartItem::query()->firstWhere('product_id', $product->id);
        $this->assertNotNull($cartItem);
        $this->assertSame(2, $cartItem->quantity);
        $this->assertSame($tomorrow, $cartItem->delivery_date?->format('Y-m-d'));
    }

    public function test_add_to_cart_without_delivery_date_leaves_null(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertOk();

        $cartItem = CartItem::query()->firstWhere('product_id', $product->id);
        $this->assertNotNull($cartItem);
        $this->assertNull($cartItem->delivery_date);
    }

    public function test_add_to_cart_rejects_past_delivery_date(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 1,
            'deliveryDate' => now()->subDay()->format('Y-m-d'),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['deliveryDate']);
    }

    public function test_add_to_cart_rejects_invalid_date_format(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 1,
            'deliveryDate' => '11.04.2026',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['deliveryDate']);
    }

    public function test_add_to_cart_merge_does_not_overwrite_existing_delivery_date_with_null(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $existingDate = now()->addDays(3)->format('Y-m-d');

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        $existingItem = CartItem::factory()
            ->for($cart)
            ->for($product)
            ->create([
                'store_id' => $product->store_id,
                'quantity' => 1,
                'price' => (int) $product->price,
                'delivery_date' => $existingDate,
            ]);

        // Второй add без deliveryDate — существующая дата не должна затираться.
        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertOk();

        $existingItem->refresh();
        $this->assertSame(3, $existingItem->quantity);
        $this->assertSame($existingDate, $existingItem->delivery_date?->format('Y-m-d'));
    }

    public function test_add_to_cart_merge_overwrites_existing_delivery_date_with_new_value(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $oldDate = now()->addDays(3)->format('Y-m-d');
        $newDate = now()->addDays(5)->format('Y-m-d');

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        $existingItem = CartItem::factory()
            ->for($cart)
            ->for($product)
            ->create([
                'store_id' => $product->store_id,
                'quantity' => 1,
                'price' => (int) $product->price,
                'delivery_date' => $oldDate,
            ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 2,
            'deliveryDate' => $newDate,
        ]);

        $response->assertOk();

        $existingItem->refresh();
        $this->assertSame(3, $existingItem->quantity);
        $this->assertSame($newDate, $existingItem->delivery_date?->format('Y-m-d'));
    }

    // ==================== UpdateCartItem with deliveryDate ====================

    public function test_update_cart_item_sets_delivery_date(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $newDate = now()->addDays(2)->format('Y-m-d');

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        $cartItem = CartItem::factory()
            ->for($cart)
            ->for($product)
            ->create([
                'store_id' => $product->store_id,
                'quantity' => 1,
                'price' => (int) $product->price,
                'delivery_date' => null,
            ]);

        $response = $this->actingAs($user)->putJson("/api/v1/cart/items/{$cartItem->id}", [
            'quantity' => 3,
            'deliveryDate' => $newDate,
        ]);

        $response->assertOk();

        $cartItem->refresh();
        $this->assertSame(3, $cartItem->quantity);
        $this->assertSame($newDate, $cartItem->delivery_date?->format('Y-m-d'));
    }

    public function test_update_cart_item_without_delivery_date_does_not_overwrite_existing(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $existingDate = now()->addDays(2)->format('Y-m-d');

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        $cartItem = CartItem::factory()
            ->for($cart)
            ->for($product)
            ->create([
                'store_id' => $product->store_id,
                'quantity' => 1,
                'price' => (int) $product->price,
                'delivery_date' => $existingDate,
            ]);

        $response = $this->actingAs($user)->putJson("/api/v1/cart/items/{$cartItem->id}", [
            'quantity' => 4,
        ]);

        $response->assertOk();

        $cartItem->refresh();
        $this->assertSame(4, $cartItem->quantity);
        $this->assertSame($existingDate, $cartItem->delivery_date?->format('Y-m-d'));
    }

    // ==================== ClientCartResource (marketplace mode) ====================

    public function test_client_cart_resource_groups_by_delivery_date(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product1 = Product::factory()->for($store)->inCategory($category)->create(['price' => 300, 'quantity' => 10]);
        $product2 = Product::factory()->for($store)->inCategory($category)->create(['price' => 200, 'quantity' => 10]);
        $product3 = Product::factory()->for($store)->inCategory($category)->create(['price' => 500, 'quantity' => 10]);

        $dateA = now()->addDays(1)->format('Y-m-d');
        $dateB = now()->addDays(3)->format('Y-m-d');

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product1)->create([
            'store_id' => $store->id, 'quantity' => 1, 'price' => 300, 'delivery_date' => $dateA,
        ]);
        CartItem::factory()->for($cart)->for($product2)->create([
            'store_id' => $store->id, 'quantity' => 2, 'price' => 200, 'delivery_date' => $dateA,
        ]);
        CartItem::factory()->for($cart)->for($product3)->create([
            'store_id' => $store->id, 'quantity' => 1, 'price' => 500, 'delivery_date' => $dateB,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $json = $response->json();

        $this->assertCount(2, $json['dateGroups']);

        $groupsByDate = collect($json['dateGroups'])->keyBy('date');
        $this->assertArrayHasKey($dateA, $groupsByDate);
        $this->assertArrayHasKey($dateB, $groupsByDate);

        $this->assertSame(2, $groupsByDate[$dateA]['itemsCount']);
        $this->assertSame(1, $groupsByDate[$dateB]['itemsCount']);

        $this->assertTrue($json['isMultiDate']);
        $this->assertContains($dateA, $json['estimatedDates']);
        $this->assertContains($dateB, $json['estimatedDates']);
    }

    public function test_client_cart_resource_is_not_multi_date_with_one_date_and_one_null(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product1 = Product::factory()->for($store)->inCategory($category)->create(['price' => 300, 'quantity' => 10]);
        $product2 = Product::factory()->for($store)->inCategory($category)->create(['price' => 200, 'quantity' => 10]);

        $dateA = now()->addDays(1)->format('Y-m-d');

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product1)->create([
            'store_id' => $store->id, 'quantity' => 1, 'price' => 300, 'delivery_date' => $dateA,
        ]);
        CartItem::factory()->for($cart)->for($product2)->create([
            'store_id' => $store->id, 'quantity' => 1, 'price' => 200, 'delivery_date' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $json = $response->json();

        // 2 группы: одна с датой, одна с null
        $this->assertCount(2, $json['dateGroups']);
        // но isMultiDate = false т.к. непустая дата всего одна
        $this->assertFalse($json['isMultiDate']);
    }

    public function test_client_cart_resource_backward_compat_all_null_dates(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product1 = Product::factory()->for($store)->inCategory($category)->create(['price' => 300, 'quantity' => 10]);
        $product2 = Product::factory()->for($store)->inCategory($category)->create(['price' => 200, 'quantity' => 10]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product1)->create([
            'store_id' => $store->id, 'quantity' => 1, 'price' => 300, 'delivery_date' => null,
        ]);
        CartItem::factory()->for($cart)->for($product2)->create([
            'store_id' => $store->id, 'quantity' => 2, 'price' => 200, 'delivery_date' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $json = $response->json();

        // Все items без даты → одна группа с date=null
        $this->assertCount(1, $json['dateGroups']);
        $this->assertNull($json['dateGroups'][0]['date']);
        $this->assertSame(2, $json['dateGroups'][0]['itemsCount']);
        $this->assertFalse($json['isMultiDate']);
    }

    public function test_client_cart_resource_item_includes_delivery_date_field(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['price' => 300, 'quantity' => 10]);

        $dateA = now()->addDays(1)->format('Y-m-d');

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id, 'quantity' => 1, 'price' => 300, 'delivery_date' => $dateA,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $item = $response->json('dateGroups.0.items.0');
        $this->assertSame($dateA, $item['deliveryDate']);
    }

    // ==================== Regression: standard CartResource shape ====================

    public function test_standard_cart_item_response_does_not_include_delivery_date_field(): void
    {
        // marketplace mode OFF — shape стандартного CartResource не меняется.
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['price' => 150, 'quantity' => 10]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 150,
            'delivery_date' => now()->addDay()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $item = $response->json('storeGroups.0.items.0');
        $this->assertIsArray($item);
        // В standard shape новое поле не появляется.
        $this->assertArrayNotHasKey('deliveryDate', $item);
    }

    // ==================== Helpers ====================

    private function createProduct(): Product
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        return Product::factory()->for($store)->inCategory($category)->create([
            'price' => 150,
            'quantity' => 10,
        ]);
    }

    private function enableMarketplaceMode(): void
    {
        // Ключ project_modules уже засеян миграцией seed_address_objects_module,
        // поэтому create() бьётся об UNIQUE storefront_settings.key.
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );
        app(ModuleConfigService::class)->invalidateCache();
    }
}
