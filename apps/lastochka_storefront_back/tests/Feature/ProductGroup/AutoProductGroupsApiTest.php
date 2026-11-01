<?php

declare(strict_types=1);

namespace Tests\Feature\ProductGroup;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductView;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Ручки товарных групп с автоматическим наполнением (fillType):
 * списки, детали, товары группы по id и по slug.
 */
final class AutoProductGroupsApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->store = Store::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    // ==================== Списки групп ====================

    public function test_home_returns_fill_type_and_auto_group_products(): void
    {
        $manual = $this->group('manual', ['title' => 'Ручная', 'position' => 0]);
        $manualProduct = $this->product();
        $manual->items()->create(['product_id' => $manualProduct->id, 'position' => 0, 'quantity' => 1]);

        $this->group('is_new', ['title' => 'Новинки', 'position' => 1]);
        $older = $this->product(['is_new' => true]);
        $newer = $this->product(['is_new' => true]);
        $this->product();

        $response = $this->getJson('/api/v1/product-groups?placement=home&withProducts=1');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.fillType', 'manual')
            ->assertJsonPath('data.0.productsCount', 1)
            ->assertJsonPath('data.0.products.0.id', $manualProduct->id)
            ->assertJsonPath('data.1.fillType', 'is_new')
            ->assertJsonPath('data.1.productsCount', 2)
            ->assertJsonPath('data.1.products.0.id', $newer->id)
            ->assertJsonPath('data.1.products.1.id', $older->id);
    }

    public function test_home_limits_auto_group_products_but_counts_all(): void
    {
        $this->group('is_hurry_up');
        foreach (range(1, 5) as $i) {
            $this->product(['is_hurry_up' => true]);
        }

        $this->getJson('/api/v1/product-groups?placement=home&withProducts=1&productsLimit=2')
            ->assertOk()
            ->assertJsonPath('data.0.productsCount', 5)
            ->assertJsonCount(2, 'data.0.products');
    }

    public function test_home_auto_group_without_products_param_has_count_only(): void
    {
        $this->group('is_new');
        $this->product(['is_new' => true]);

        $response = $this->getJson('/api/v1/product-groups?placement=home')
            ->assertOk()
            ->assertJsonPath('data.0.productsCount', 1);

        $this->assertArrayNotHasKey('products', $response->json('data.0'));
    }

    public function test_home_hides_personal_groups_from_guest(): void
    {
        $this->group('manual', ['title' => 'Ручная']);
        $this->group('purchase_history', ['title' => 'История']);
        $this->group('viewed_not_bought', ['title' => 'Смотрел']);
        $this->deliveredOrder(3);

        $this->getJson('/api/v1/product-groups?placement=home&withProducts=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Ручная');
    }

    public function test_home_shows_personal_group_to_user_with_enough_products(): void
    {
        $this->group('purchase_history', ['title' => 'История']);
        $products = $this->deliveredOrder(3);

        $this->withToken($this->token)
            ->getJson('/api/v1/product-groups?placement=home&withProducts=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fillType', 'purchase_history')
            ->assertJsonPath('data.0.productsCount', 3)
            ->assertJsonCount(3, 'data.0.products');

        $this->assertCount(3, $products);
    }

    public function test_home_hides_personal_group_below_threshold(): void
    {
        $this->group('purchase_history');
        $this->deliveredOrder(2);

        $this->withToken($this->token)
            ->getJson('/api/v1/product-groups?placement=home&withProducts=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_store_index_applies_same_rules(): void
    {
        $this->group('viewed_not_bought', ['title' => 'Смотрел', 'show_on_store_page' => true]);
        foreach (range(1, 3) as $i) {
            ProductView::create([
                'user_id' => $this->user->id,
                'product_id' => $this->product()->id,
                'views_count' => 1,
                'first_viewed_at' => now(),
                'last_viewed_at' => now(),
            ]);
        }

        $this->getJson("/api/v1/stores/{$this->store->id}/product-groups?placement=store_page")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($this->token)
            ->getJson("/api/v1/stores/{$this->store->id}/product-groups?placement=store_page&withProducts=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fillType', 'viewed_not_bought')
            ->assertJsonPath('data.0.productsCount', 3)
            ->assertJsonCount(3, 'data.0.products');
    }

    // ==================== Детали ====================

    public function test_show_counts_auto_group_products(): void
    {
        $group = $this->group('purchase_history');
        $this->deliveredOrder(4);

        $this->getJson("/api/v1/product-groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.fillType', 'purchase_history')
            ->assertJsonPath('data.productsCount', 0);

        $this->withToken($this->token)
            ->getJson("/api/v1/product-groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.productsCount', 4);
    }

    // ==================== Товары группы ====================

    public function test_products_of_personal_group_empty_for_guest(): void
    {
        $group = $this->group('purchase_history');
        $this->deliveredOrder(3);

        $this->getJson("/api/v1/product-groups/{$group->id}/products")
            ->assertOk()
            ->assertExactJson(['hasMore' => false, 'data' => [], 'total' => 0]);
    }

    public function test_products_of_personal_group_empty_below_threshold(): void
    {
        $group = $this->group('purchase_history');
        $this->deliveredOrder(2);

        $this->withToken($this->token)
            ->getJson("/api/v1/product-groups/{$group->id}/products")
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_products_of_personal_group_for_user_in_rule_order(): void
    {
        $group = $this->group('purchase_history');
        $old = $this->deliveredOrder(2, now()->subDays(3));
        $fresh = $this->deliveredOrder(1, now()->subDay());

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/product-groups/{$group->id}/products?perPage=2")
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('hasMore', true)
            ->assertJsonCount(2, 'data');

        $this->assertSame($fresh[0]->id, $response->json('data.0.id'));
        $this->assertContains($response->json('data.1.id'), [$old[0]->id, $old[1]->id]);
    }

    public function test_products_of_general_auto_group_for_guest(): void
    {
        $group = $this->group('is_new');
        $a = $this->product(['is_new' => true]);
        $b = $this->product(['is_new' => true]);

        $this->getJson("/api/v1/product-groups/{$group->id}/products")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $b->id)
            ->assertJsonPath('data.1.id', $a->id);
    }

    public function test_products_of_auto_group_hide_adult_for_guest(): void
    {
        $group = $this->group('is_new');
        $this->product(['is_new' => true, 'adult_only' => true]);
        $plain = $this->product(['is_new' => true]);

        $this->getJson("/api/v1/product-groups/{$group->id}/products")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $plain->id);
    }

    public function test_products_of_manual_group_unchanged(): void
    {
        $group = $this->group('manual');
        $second = $this->product();
        $first = $this->product();
        $group->items()->create(['product_id' => $second->id, 'position' => 1, 'quantity' => 1]);
        $group->items()->create(['product_id' => $first->id, 'position' => 0, 'quantity' => 1]);

        $this->getJson("/api/v1/product-groups/{$group->id}/products")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);
    }

    // ==================== Товары группы по slug ====================

    public function test_products_by_slug_personal_group_empty_for_guest(): void
    {
        $group = $this->group('purchase_history', ['slug' => 'history', 'title' => 'История']);
        $this->deliveredOrder(3);

        $this->getJson("/api/v1/stores/{$this->store->id}/product-groups/slug/history/products")
            ->assertOk()
            ->assertJsonPath('group.id', $group->id)
            ->assertJsonPath('group.title', 'История')
            ->assertJsonPath('hasMore', false)
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_products_by_slug_default_order_is_rule_order(): void
    {
        $this->group('is_new', ['slug' => 'new']);
        $cheapOld = $this->product(['is_new' => true, 'price' => 50]);
        $pricyNew = $this->product(['is_new' => true, 'price' => 500]);

        $this->getJson("/api/v1/stores/{$this->store->id}/product-groups/slug/new/products")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $pricyNew->id)
            ->assertJsonPath('data.1.id', $cheapOld->id);
    }

    public function test_products_by_slug_explicit_sort_overrides_rule_order(): void
    {
        $this->group('is_new', ['slug' => 'new']);
        $cheapOld = $this->product(['is_new' => true, 'price' => 50]);
        $pricyNew = $this->product(['is_new' => true, 'price' => 500]);

        $this->getJson("/api/v1/stores/{$this->store->id}/product-groups/slug/new/products?sort=price_asc")
            ->assertOk()
            ->assertJsonPath('data.0.id', $cheapOld->id)
            ->assertJsonPath('data.1.id', $pricyNew->id);
    }

    public function test_products_by_slug_filters_work_for_auto_group(): void
    {
        $this->group('is_new', ['slug' => 'new']);
        $this->product(['is_new' => true, 'price' => 50]);
        $discounted = $this->product(['is_new' => true, 'price' => 500, 'price_old' => 700]);
        $this->product(['is_new' => true, 'price' => 900]);

        $this->getJson("/api/v1/stores/{$this->store->id}/product-groups/slug/new/products?onlyDiscounted=1")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $discounted->id);

        $this->getJson("/api/v1/stores/{$this->store->id}/product-groups/slug/new/products?priceMin=100&priceMax=800")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $discounted->id);
    }

    public function test_products_by_slug_viewed_not_bought_sorted_by_rank_for_user(): void
    {
        $this->group('viewed_not_bought', ['slug' => 'for-you']);
        $low = $this->product();
        $high = $this->product();
        $mid = $this->product();

        foreach ([[$low, 1], [$high, 5], [$mid, 3]] as [$product, $views]) {
            ProductView::create([
                'user_id' => $this->user->id,
                'product_id' => $product->id,
                'views_count' => $views,
                'first_viewed_at' => now(),
                'last_viewed_at' => now(),
            ]);
        }

        $this->withToken($this->token)
            ->getJson("/api/v1/stores/{$this->store->id}/product-groups/slug/for-you/products")
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('data.0.id', $high->id)
            ->assertJsonPath('data.1.id', $mid->id)
            ->assertJsonPath('data.2.id', $low->id);
    }

    public function test_products_by_slug_network_catalog_uses_group_store_offers(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode(['network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => true]]),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();

        $otherStore = Store::factory()->create(['is_active' => true]);
        $this->group('viewed_not_bought', ['slug' => 'for-you']);

        $offers = [];
        foreach ([[$this->store, 300, 1], [$this->store, 100, 3], [$this->store, 200, 2], [$otherStore, 50, 9]] as [$store, $price, $views]) {
            $product = $this->product(['price' => 0, 'quantity' => 0]);
            StoreProduct::create(['store_id' => $store->id, 'product_id' => $product->id, 'price' => $price, 'quantity' => 5, 'is_active' => true]);
            ProductView::create([
                'user_id' => $this->user->id,
                'product_id' => $product->id,
                'views_count' => $views,
                'first_viewed_at' => now(),
                'last_viewed_at' => now(),
            ]);
            $offers[] = $product;
        }

        // По рангу (views_count)
        $this->withToken($this->token)
            ->getJson("/api/v1/stores/{$this->store->id}/product-groups/slug/for-you/products")
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('data.0.id', $offers[1]->id)
            ->assertJsonPath('data.1.id', $offers[2]->id)
            ->assertJsonPath('data.2.id', $offers[0]->id);

        // Явная сортировка по цене оффера магазина группы
        $this->withToken($this->token)
            ->getJson("/api/v1/stores/{$this->store->id}/product-groups/slug/for-you/products?sort=price_desc&priceMax=250")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $offers[2]->id)
            ->assertJsonPath('data.1.id', $offers[1]->id);
    }

    // ==================== Helpers ====================

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function group(string $fillType, array $attributes = []): ProductGroup
    {
        return ProductGroup::factory()->for($this->store)->create(array_merge([
            'fill_type' => $fillType,
            'show_on_home' => true,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'store_id' => $this->store->id,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Доставленный заказ пользователя с $count новыми товарами.
     *
     * @return array<int, Product>
     */
    private function deliveredOrder(int $count, mixed $deliveredAt = null): array
    {
        $order = Order::factory()->for($this->user)->for($this->store)->create([
            'status' => OrderStatus::Delivered,
            'delivered_at' => $deliveredAt ?? now(),
        ]);

        $products = [];
        foreach (range(1, $count) as $i) {
            $product = $this->product();
            $order->items()->create([
                'product_id' => $product->id,
                'title' => $product->title,
                'sku' => (string) $product->sku,
                'price' => 100,
                'quantity' => 1,
                'total' => 100,
                'created_at' => now(),
            ]);
            $products[] = $product;
        }

        return $products;
    }
}
