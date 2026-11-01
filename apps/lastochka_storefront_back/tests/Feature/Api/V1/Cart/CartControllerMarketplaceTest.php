<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Cart;

use App\Models\Address;
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

final class CartControllerMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_cart_index_returns_store_groups_shape_in_standard_mode(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $json = $response->json();
        // Пустая корзина в standard режиме → {storeGroups: []}
        $this->assertArrayHasKey('storeGroups', $json);
        $this->assertArrayNotHasKey('dateGroups', $json);
    }

    public function test_cart_index_returns_date_groups_shape_in_marketplace_mode_when_empty(): void
    {
        $this->enableMarketplaceMode();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonStructure([
                'dateGroups',
                'totalAmount',
                'totalShippingPrice',
                'freeShippingFrom',
                'isMultiDate',
                'estimatedDates',
            ]);
        $this->assertArrayNotHasKey('storeGroups', $response->json());
    }

    public function test_cart_index_returns_marketplace_cart_with_items_grouped(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store1, $store2] = $this->seedCartWithItems();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonStructure([
                'dateGroups' => [
                    ['date', 'items', 'subtotal', 'itemsCount'],
                ],
                'totalAmount',
                'totalShippingPrice',
            ]);

        // Все items должны попасть в один dateGroup с date=null (MVP — нет delivery_date на CartItem)
        $this->assertCount(1, $response->json('dateGroups'));
        $this->assertNull($response->json('dateGroups.0.date'));
        $this->assertSame(2, $response->json('dateGroups.0.itemsCount'));
    }

    public function test_delivery_summary_returns_404_in_standard_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/cart/delivery-summary')
            ->assertNotFound();
    }

    public function test_delivery_summary_returns_aggregate_in_marketplace_mode(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store1, $store2] = $this->seedCartWithItems();

        $response = $this->actingAs($user)->getJson('/api/v1/cart/delivery-summary');

        $response->assertOk()
            ->assertJsonStructure([
                'totalShippingPrice',
                'freeShippingFrom',
                'estimatedDates',
                'isMultiDate',
                'dateGroups',
            ]);
    }

    public function test_delivery_summary_respects_marketplace_free_shipping_threshold(): void
    {
        $this->enableMarketplaceMode();

        // Порог 100 — гарантированно ниже любого разумного cart total
        StorefrontSetting::create([
            'key' => ModuleConfigService::FREE_SHIPPING_THRESHOLD_KEY,
            'value' => '100',
            'type' => 'int',
        ]);
        app(ModuleConfigService::class)->invalidateCache();

        [$user] = $this->seedCartWithItems();

        $response = $this->actingAs($user)->getJson('/api/v1/cart/delivery-summary');

        $response->assertOk();
        $this->assertSame(100.0, (float) $response->json('freeShippingFrom'));
        $this->assertSame(0.0, (float) $response->json('totalShippingPrice'));
    }

    /**
     * @return array{0: User, 1: Store, 2: Store}
     */
    private function seedCartWithItems(): array
    {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create();

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
        ]);
        $product2 = Product::factory()->for($store2)->inCategory($cat2)->create([
            'price' => 400,
            'quantity' => 10,
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

        return [$user, $store1, $store2];
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
            ],
        );
        app(ModuleConfigService::class)->invalidateCache();
    }
}
