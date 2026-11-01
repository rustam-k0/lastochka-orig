<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * P2.3 — Platform-wide промокоды.
 *
 * Покрывают:
 *  1) apply platform-wide промокод без storeId → успех, discount на всю корзину.
 *  2) apply store-scoped промокод без storeId → 422 (storeId обязателен для store-scoped).
 *  3) apply + remove platform-wide → CartPromocode с store_id=null удалён.
 *  4) ClientCartResource содержит promocode блок с appliedToItemsCount/totalItemsCount/isPartialApplication.
 *  5) Platform-wide промокод не ломает standard mode (apply требует storeId).
 */
final class PlatformWidePromocodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_apply_platform_wide_promocode_without_store_id_in_marketplace_mode(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store1, $store2, $product1, $product2] = $this->seedTwoStoreCart();

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLATFORM10',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/promocode', [
            'promocode' => 'PLATFORM10',
        ]);

        $response->assertOk();

        // cart_promocodes содержит одну запись с store_id=null и promocode_id.
        $this->assertDatabaseHas('cart_promocodes', [
            'store_id' => null,
            'promocode_id' => $promocode->id,
        ]);

        // Discount 10% от (300*2 + 400*1) = 10% от 1000 = 100 (platform-wide — все items).
        $this->assertSame(100, $response->json('totalDiscount'));
    }

    public function test_apply_store_scoped_promocode_without_store_id_fails(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store1] = $this->seedTwoStoreCart();

        Promocode::factory()->percent(15)->create([
            'store_id' => $store1->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'STORE15',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/promocode', [
            'promocode' => 'STORE15',
            // storeId нет
        ]);

        // Router в action выкидывает InvalidPromocodeException (store-scoped требует storeId).
        // Exception → 400/422, в зависимости от конвертера. Проверяем что НЕ 200.
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_remove_platform_wide_promocode_without_store_id(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store1, $store2] = $this->seedTwoStoreCart();

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLATFORM10',
        ]);

        // Apply
        $this->actingAs($user)
            ->postJson('/api/v1/cart/promocode', ['promocode' => 'PLATFORM10'])
            ->assertOk();

        $this->assertDatabaseHas('cart_promocodes', [
            'store_id' => null,
            'promocode_id' => $promocode->id,
        ]);

        // Remove без storeId — удаляется platform-wide
        $removeResponse = $this->actingAs($user)->deleteJson('/api/v1/cart/promocode', []);

        $removeResponse->assertOk();
        $this->assertDatabaseMissing('cart_promocodes', [
            'store_id' => null,
            'promocode_id' => $promocode->id,
        ]);
    }

    public function test_client_cart_resource_contains_promocode_block_with_application_stats(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store1, $store2, $product1, $product2] = $this->seedTwoStoreCart();

        // Привязываем промокод только к product1 — partial application.
        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'BOUND10',
            'binding_type' => 'products',
        ]);
        $promocode->products()->attach($product1->id);

        $this->actingAs($user)
            ->postJson('/api/v1/cart/promocode', ['promocode' => 'BOUND10'])
            ->assertOk();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');
        $response->assertOk();

        $promocodeBlock = $response->json('promocode');
        $this->assertNotNull($promocodeBlock);
        $this->assertSame('BOUND10', $promocodeBlock['code']);
        // 2 items в корзине (product1, product2), промокод привязан только к product1
        $this->assertSame(2, $promocodeBlock['totalItemsCount']);
        $this->assertSame(1, $promocodeBlock['appliedToItemsCount']);
        $this->assertTrue($promocodeBlock['isPartialApplication']);

        // Discount = 10% от price product1 * 2 = 10% от 600 = 60
        $this->assertSame(60, $promocodeBlock['discount']);
    }

    public function test_apply_platform_wide_promocode_in_standard_mode_requires_store_id(): void
    {
        // В standard режиме router НЕ заходит в applyPlatformWide даже если scope=platform.
        // Поэтому промокод уйдёт в applyStandard → InvalidPromocodeException (storeId=null).
        [$user] = $this->seedTwoStoreCart();

        Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLATFORM10',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/promocode', [
            'promocode' => 'PLATFORM10',
        ]);

        // Не 200 — standard режим требует storeId.
        $this->assertNotSame(200, $response->getStatusCode());
    }

    /**
     * @return array{0: User, 1: Store, 2: Store, 3: Product, 4: Product}
     */
    private function seedTwoStoreCart(): array
    {
        $user = User::factory()->create();

        $store1 = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $store2 = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $cat1 = Category::factory()->forStore($store1)->create();
        $cat2 = Category::factory()->forStore($store2)->create();

        $product1 = Product::factory()->for($store1)->inCategory($cat1)->create([
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $product2 = Product::factory()->for($store2)->inCategory($cat2)->create([
            'price' => 400,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product1)->create([
            'store_id' => $store1->id,
            'quantity' => 2,
            'price' => 300,
        ]);
        CartItem::factory()->for($cart)->for($product2)->create([
            'store_id' => $store2->id,
            'quantity' => 1,
            'price' => 400,
        ]);

        return [$user, $store1, $store2, $product1, $product2];
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
