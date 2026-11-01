<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartWeightTotalTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(): Store
    {
        return Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
        ]);
    }

    private function addItem(User $user, Store $store, Product $product, int $quantity): CartItem
    {
        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        return CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $quantity,
            'price' => $product->price,
        ]);
    }

    public function testWeightTotalForPiecedProducts(): void
    {
        $user = User::factory()->create();
        $store = $this->makeStore();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 50,
            'quantity_step' => 1,
            'weight' => 300,
        ]);
        $this->addItem($user, $store, $product, quantity: 2);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $this->assertSame(0.6, $response->json('weightTotal'));
        $this->assertSame(0.6, $response->json('storeGroups.0.weightTotal'));
    }

    public function testWeightTotalForWeightedProductTakesQuantityDirectly(): void
    {
        $user = User::factory()->create();
        $store = $this->makeStore();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 5000,
            'quantity_step' => 100,
            'weight' => 1,
        ]);
        $this->addItem($user, $store, $product, quantity: 850);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $this->assertSame(0.85, $response->json('weightTotal'));
        $this->assertSame(0.85, $response->json('storeGroups.0.weightTotal'));
    }

    public function testWeightTotalNullWhenProductHasNoWeight(): void
    {
        $user = User::factory()->create();
        $store = $this->makeStore();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 50,
            'quantity_step' => 1,
            'weight' => null,
        ]);
        $this->addItem($user, $store, $product, quantity: 3);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $this->assertNull($response->json('weightTotal'));
        $this->assertNull($response->json('storeGroups.0.weightTotal'));
    }

    public function testWeightTotalSumsAcrossStoreGroups(): void
    {
        $user = User::factory()->create();
        $storeA = $this->makeStore();
        $storeB = $this->makeStore();
        $categoryA = Category::factory()->forStore($storeA)->create();
        $categoryB = Category::factory()->forStore($storeB)->create();

        $productA = Product::factory()->for($storeA)->inCategory($categoryA)->create([
            'price' => 100,
            'quantity' => 50,
            'quantity_step' => 1,
            'weight' => 500,
        ]);
        $productB = Product::factory()->for($storeB)->inCategory($categoryB)->create([
            'price' => 100,
            'quantity' => 5000,
            'quantity_step' => 100,
            'weight' => 1,
        ]);

        $this->addItem($user, $storeA, $productA, quantity: 2);  // 2 × 500 = 1000 г
        $this->addItem($user, $storeB, $productB, quantity: 750); // 750 г

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $this->assertSame(1.75, $response->json('weightTotal'));

        $groups = $response->json('storeGroups');
        $this->assertCount(2, $groups);

        $byStore = collect($groups)->keyBy('id');
        $this->assertEquals(1.0, $byStore[$storeA->id]['weightTotal']);
        $this->assertSame(0.75, $byStore[$storeB->id]['weightTotal']);
    }

    private function enableMarketplaceMode(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    public function testWeightTotalInMarketplaceModeCartDateGroups(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create();
        $store = $this->makeStore();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 50,
            'quantity_step' => 1,
            'weight' => 400,
        ]);
        $this->addItem($user, $store, $product, quantity: 3); // 3 × 400 = 1200 г

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $this->assertSame(1.2, $response->json('weightTotal'));
        $this->assertSame(1.2, $response->json('dateGroups.0.weightTotal'));
    }

    public function testEmptyMarketplaceCartReturnsWeightTotalNull(): void
    {
        $this->enableMarketplaceMode();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $this->assertNull($response->json('weightTotal'));
        $this->assertSame([], $response->json('dateGroups'));
    }

    public function testEmptyStandardCartReturnsWeightTotalNull(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $this->assertNull($response->json('weightTotal'));
        $this->assertSame([], $response->json('storeGroups'));
    }
}
