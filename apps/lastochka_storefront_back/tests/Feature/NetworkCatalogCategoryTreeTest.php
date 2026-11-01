<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\Services\Search\SearchService;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Дерево категорий в сетевом каталоге.
 *
 * 1С отдаёт категории привязанными к ОДНОМУ магазину (на dev в
 * `category_store` один distinct store_id на 800+ магазинов), поэтому
 * pivot-фильтр в сетевом режиме оставлял бы витрину любого другого
 * магазина с пустым деревом. В сетевом режиме категории общие на сеть:
 * видимость из `categories.is_active`, порядок из `categories.position`.
 *
 * Легаси-режим должен остаться прежним: JOIN на pivot `category_store`.
 */
final class NetworkCatalogCategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    /** Магазин-владелец глобальных карточек (минимальный id). */
    private Store $anchorStore;

    /** Магазин, к которому 1С привязала категории (есть строки category_store). */
    private Store $boundStore;

    /** Магазин без единой строки в category_store. */
    private Store $unboundStore;

    private Category $parent;

    private Category $child;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Product::unsetEventDispatcher();

        $this->anchorStore = Store::factory()->create(['code' => 'tree-anchor', 'is_active' => true]);
        $this->boundStore = Store::factory()->create(['code' => 'tree-bound', 'is_active' => true]);
        $this->unboundStore = Store::factory()->create(['code' => 'tree-unbound', 'is_active' => true]);

        // Привязка только к boundStore — ровно контракт 1С.
        $this->parent = Category::factory()
            ->forStore($this->boundStore)
            ->create([
                'name' => 'Алкоголь',
                'slug' => 'alkogol',
                'position' => 1,
                'is_active' => true,
                'show_on_main' => true,
            ]);

        $this->child = Category::factory()
            ->childOf($this->parent)
            ->forStore($this->boundStore)
            ->create([
                'name' => 'Вино',
                'slug' => 'vino',
                'position' => 2,
                'is_active' => true,
            ]);

        $this->product = Product::factory()
            ->inCategory($this->child)
            ->create([
                'store_id' => $this->anchorStore->id,
                'title' => 'Вино столовое',
                'is_active' => true,
                'price' => 0,
                'quantity' => 0,
                'quantity_step' => 1,
            ]);
    }

    /**
     * Флаг модуля живёт в storefront_settings.project_modules (type=json).
     * ModuleConfigService — singleton с мемоизацией: сбрасываем и кеш, и мемо.
     */
    private function setNetworkCatalog(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => $enabled],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
    }

    /** Оффер товара в магазине — «товар есть в этом магазине». */
    private function offerIn(Store $store, float $price = 500.0, float $quantity = 7.0): void
    {
        StoreProduct::create([
            'store_id' => $store->id,
            'product_id' => $this->product->id,
            'price' => $price,
            'quantity' => $quantity,
        ]);
    }

    // ==================== Сетевой режим ====================

    public function test_network_root_categories_returned_for_store_without_pivot_rows(): void
    {
        $this->setNetworkCatalog(true);

        $response = $this->getJson("/api/v1/stores/{$this->unboundStore->id}/categories");

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $this->parent->id);
    }

    public function test_network_children_returned_for_store_without_pivot_rows(): void
    {
        $this->setNetworkCatalog(true);

        $response = $this->getJson(
            "/api/v1/stores/{$this->unboundStore->id}/categories/{$this->parent->id}/children"
        );

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $this->child->id);
    }

    public function test_network_home_categories_returned_for_store_without_pivot_rows(): void
    {
        $this->setNetworkCatalog(true);

        $response = $this->getJson("/api/v1/stores/{$this->unboundStore->id}/categories/home");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->parent->id);
    }

    public function test_network_include_children_tree_returned_for_store_without_pivot_rows(): void
    {
        $this->setNetworkCatalog(true);

        $response = $this->getJson(
            "/api/v1/stores/{$this->unboundStore->id}/categories?includeChildren=true"
        );

        $response->assertOk()
            ->assertJsonPath('data.0.id', $this->parent->id)
            ->assertJsonCount(1, 'data.0.children')
            ->assertJsonPath('data.0.children.0.id', $this->child->id);
    }

    /**
     * Порядок в сетевом режиме — глобальный `categories.position`, а НЕ
     * pivot-позиция. Ставим pivot-позиции в обратном порядке: если бы
     * сортировка шла по pivot, выдача была бы перевёрнута.
     */
    public function test_network_ordering_uses_global_position_not_pivot(): void
    {
        $second = Category::factory()->create([
            'name' => 'Бакалея',
            'slug' => 'bakaleya',
            'position' => 5,
            'is_active' => true,
        ]);

        $this->parent->stores()->syncWithoutDetaching([
            $this->boundStore->id => ['position' => 99, 'is_active' => true],
        ]);
        $second->stores()->attach($this->boundStore->id, ['position' => 0, 'is_active' => true]);

        $this->setNetworkCatalog(true);

        $response = $this->getJson("/api/v1/stores/{$this->unboundStore->id}/categories");

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $this->parent->id)
            ->assertJsonPath('data.1.id', $second->id);
    }

    public function test_network_inactive_category_still_hidden(): void
    {
        $this->setNetworkCatalog(true);
        $this->parent->update(['is_active' => false]);

        $response = $this->getJson("/api/v1/stores/{$this->unboundStore->id}/categories");

        $response->assertOk()->assertJsonPath('total', 0);
    }

    /**
     * Счётчик товаров категории (задача 12b) считается по офферам магазина
     * запроса, а не по привязке категории: в магазине с оффером счётчик
     * ненулевой, в магазине без оффера — ноль.
     */
    public function test_network_products_count_follows_store_offer(): void
    {
        $this->offerIn($this->unboundStore);
        $this->setNetworkCatalog(true);

        $withOffer = $this->getJson(
            "/api/v1/stores/{$this->unboundStore->id}/categories/{$this->parent->id}/children"
        );
        $withOffer->assertOk()->assertJsonPath('data.0.isLeaf', true);

        $counts = Category::query()
            ->where('id', $this->child->id)
            ->withCount(['products' => fn ($q) => $q->purchasableInStoreExists($this->unboundStore->id)])
            ->first();
        $this->assertSame(1, (int) $counts->products_count);

        $countsElsewhere = Category::query()
            ->where('id', $this->child->id)
            ->withCount(['products' => fn ($q) => $q->purchasableInStoreExists($this->boundStore->id)])
            ->first();
        $this->assertSame(0, (int) $countsElsewhere->products_count);
    }

    public function test_network_products_by_category_slug_works_for_store_without_pivot_rows(): void
    {
        $this->offerIn($this->unboundStore);
        $this->setNetworkCatalog(true);

        $response = $this->getJson(
            "/api/v1/stores/{$this->unboundStore->id}/categories/slug/{$this->child->slug}/products"
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->product->id);
    }

    public function test_network_filters_by_slug_works_for_store_without_pivot_rows(): void
    {
        $this->offerIn($this->unboundStore);
        $this->setNetworkCatalog(true);

        $response = $this->getJson(
            "/api/v1/stores/{$this->unboundStore->id}/categories/slug/{$this->child->slug}/filters"
        );

        $response->assertOk()->assertJsonPath('data.0.code', 'price');
    }

    /**
     * menu-sections делает addSelect позиции поверх orderedForStore:
     * в сетевом режиме pivot не приджойнен, поэтому колонка обязана быть
     * `categories.position` (иначе SQL падает на неизвестной колонке).
     */
    public function test_network_menu_sections_returns_categories_for_store_without_pivot_rows(): void
    {
        $restaurant = Store::factory()->create([
            'code' => 'tree-restaurant',
            'is_active' => true,
            'type' => 'restaurant',
        ]);
        $this->offerIn($restaurant);
        $this->setNetworkCatalog(true);

        $response = $this->getJson("/api/v1/stores/{$restaurant->id}/menu-sections");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->parent->id);
    }

    public function test_network_search_category_suggestions_ignore_pivot(): void
    {
        $unbound = Category::factory()->create([
            'name' => 'Виски',
            'slug' => 'viski',
            'is_active' => true,
        ]);

        $this->setNetworkCatalog(true);

        $result = app(SearchService::class)->search(query: 'виск');

        $this->assertContains($unbound->id, $result['categories']->pluck('id')->all());
    }

    public function test_legacy_search_category_suggestions_require_pivot(): void
    {
        $unbound = Category::factory()->create([
            'name' => 'Виски',
            'slug' => 'viski',
            'is_active' => true,
        ]);

        $this->setNetworkCatalog(false);

        $result = app(SearchService::class)->search(query: 'виск');

        $this->assertNotContains($unbound->id, $result['categories']->pluck('id')->all());
    }

    // ==================== Легаси-режим (байт-в-байт прежний) ====================

    public function test_legacy_root_categories_hidden_for_store_without_pivot_rows(): void
    {
        $this->setNetworkCatalog(false);

        $this->getJson("/api/v1/stores/{$this->unboundStore->id}/categories")
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson("/api/v1/stores/{$this->boundStore->id}/categories")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $this->parent->id);
    }

    public function test_legacy_children_hidden_for_store_without_pivot_rows(): void
    {
        $this->setNetworkCatalog(false);

        $this->getJson("/api/v1/stores/{$this->unboundStore->id}/categories/{$this->parent->id}/children")
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson("/api/v1/stores/{$this->boundStore->id}/categories/{$this->parent->id}/children")
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_legacy_home_hidden_for_store_without_pivot_rows(): void
    {
        $this->setNetworkCatalog(false);

        $this->getJson("/api/v1/stores/{$this->unboundStore->id}/categories/home")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/stores/{$this->boundStore->id}/categories/home")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_legacy_ordering_uses_pivot_position(): void
    {
        $second = Category::factory()->create([
            'name' => 'Бакалея',
            'slug' => 'bakaleya',
            'position' => 5,
            'is_active' => true,
        ]);

        $this->parent->stores()->syncWithoutDetaching([
            $this->boundStore->id => ['position' => 99, 'is_active' => true],
        ]);
        $second->stores()->attach($this->boundStore->id, ['position' => 0, 'is_active' => true]);

        $this->setNetworkCatalog(false);

        $this->getJson("/api/v1/stores/{$this->boundStore->id}/categories")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $this->parent->id);
    }

    public function test_legacy_inactive_pivot_hides_category(): void
    {
        $this->setNetworkCatalog(false);

        $this->parent->stores()->syncWithoutDetaching([
            $this->boundStore->id => ['position' => 1, 'is_active' => false],
        ]);

        $this->getJson("/api/v1/stores/{$this->boundStore->id}/categories")
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_legacy_products_by_category_slug_404_for_store_without_pivot_rows(): void
    {
        $this->setNetworkCatalog(false);

        $this->getJson(
            "/api/v1/stores/{$this->unboundStore->id}/categories/slug/{$this->child->slug}/products"
        )->assertNotFound();
    }

    public function test_legacy_filters_by_slug_404_for_store_without_pivot_rows(): void
    {
        $this->setNetworkCatalog(false);

        $this->getJson(
            "/api/v1/stores/{$this->unboundStore->id}/categories/slug/{$this->child->slug}/filters"
        )->assertNotFound();
    }
}
