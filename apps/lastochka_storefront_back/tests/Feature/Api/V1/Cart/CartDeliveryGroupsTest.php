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

/**
 * Технический блок deliveryGroups в marketplace-ответах корзины:
 * список магазинов корзины с привязкой позиций, чтобы клиент знал,
 * для каких storeId запрашивать слоты доставки при courier/pickup сабмите.
 */
final class CartDeliveryGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_marketplace_cart_includes_delivery_groups(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store1, $store2, $item1, $item2] = $this->seedCartWithTwoStores();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonStructure([
                'deliveryGroups' => [
                    ['storeId', 'itemIds', 'itemsCount'],
                ],
            ]);

        $groups = collect($response->json('deliveryGroups'))->keyBy('storeId');
        $this->assertCount(2, $groups);
        $this->assertSame([$item1->id], $groups[$store1->id]['itemIds']);
        $this->assertSame([$item2->id], $groups[$store2->id]['itemIds']);
        $this->assertSame(1, $groups[$store1->id]['itemsCount']);
        $this->assertSame(1, $groups[$store2->id]['itemsCount']);
    }

    public function test_delivery_groups_count_only_root_items(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store1, , $item1] = $this->seedCartWithTwoStores();

        // Дочерняя позиция (товарная добавка) — не должна попасть в itemIds
        $childProduct = Product::factory()->for($store1)->create([
            'price' => 50,
            'quantity' => 10,
        ]);
        CartItem::factory()
            ->for(Cart::query()->where('user_id', $user->id)->firstOrFail())
            ->for($childProduct)
            ->create([
                'store_id' => $store1->id,
                'parent_id' => $item1->id,
                'quantity' => 1,
                'price' => 50,
            ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $groups = collect($response->json('deliveryGroups'))->keyBy('storeId');
        $this->assertSame([$item1->id], $groups[$store1->id]['itemIds']);
        $this->assertSame(1, $groups[$store1->id]['itemsCount']);
    }

    public function test_empty_marketplace_cart_returns_empty_delivery_groups(): void
    {
        $this->enableMarketplaceMode();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $this->assertSame([], $response->json('deliveryGroups'));
    }

    public function test_standard_cart_has_no_delivery_groups_key(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $this->assertArrayNotHasKey('deliveryGroups', $response->json());
    }

    public function test_delivery_summary_includes_delivery_groups(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store1, $store2, $item1, $item2] = $this->seedCartWithTwoStores();

        $response = $this->actingAs($user)->getJson('/api/v1/cart/delivery-summary');

        $response->assertOk()
            ->assertJsonStructure([
                'deliveryGroups' => [
                    ['storeId', 'itemIds', 'itemsCount'],
                ],
            ]);

        $groups = collect($response->json('deliveryGroups'))->keyBy('storeId');
        $this->assertCount(2, $groups);
        $this->assertSame([$item1->id], $groups[$store1->id]['itemIds']);
        $this->assertSame([$item2->id], $groups[$store2->id]['itemIds']);
    }

    public function test_delivery_summary_empty_cart_returns_empty_delivery_groups(): void
    {
        $this->enableMarketplaceMode();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart/delivery-summary');

        $response->assertOk();
        $this->assertSame([], $response->json('deliveryGroups'));
    }

    /**
     * @return array{0: User, 1: Store, 2: Store, 3: CartItem, 4: CartItem}
     */
    private function seedCartWithTwoStores(): array
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
        ]);
        $product2 = Product::factory()->for($store2)->inCategory($cat2)->create([
            'price' => 400,
            'quantity' => 10,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        $item1 = CartItem::factory()->for($cart)->for($product1)->create([
            'store_id' => $store1->id,
            'quantity' => 2,
            'price' => 300,
        ]);
        $item2 = CartItem::factory()->for($cart)->for($product2)->create([
            'store_id' => $store2->id,
            'quantity' => 1,
            'price' => 400,
        ]);

        return [$user, $store1, $store2, $item1, $item2];
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
}
