<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupItem;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\Catalog\StoreOfferContext;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Сетевой каталог (network_catalog) на ОСТАЛЬНЫХ точках выдачи цены/остатка,
 * кроме основного листинга категории (он покрыт NetworkCatalogCatalogApiTest):
 * дерево категорий и его счётчики, price-range/фильтры, поиск по штрихкоду,
 * товарные группы, избранное, секции меню, товары акции, вариации карточки,
 * похожие/сопутствующие без контекста магазина.
 *
 * Контракт для каждой поверхности:
 * (а) видимость товара — по офферу магазина контекста;
 * (б) цена/остаток — из store_products этого магазина;
 * (в) счётчики — по той же видимости, что и листинг.
 * При выключенном флаге поведение прежнее (легаси байт-в-байт).
 */
class NetworkCatalogPriceSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private Store $anchorStore;

    private Store $storeA;

    private Store $storeB;

    private Category $category;

    /** Товар сети с оффером только в магазине A (350/400, остаток 5). */
    private Product $product;

    /** Товар сети с оффером только в магазине B (200, остаток 5). */
    private Product $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->anchorStore = Store::factory()->create(['code' => 'anchor', 'is_active' => true]);
        $this->storeA = Store::factory()->create(['code' => 'surf-a', 'is_active' => true]);
        $this->storeB = Store::factory()->create(['code' => 'surf-b', 'is_active' => true]);

        $this->setNetworkCatalog(true);

        $this->category = Category::factory()
            ->forStore($this->storeA)
            ->forStore($this->storeB)
            ->create(['is_active' => true, 'tag_text' => 'Хит', 'tag_color' => '#ff0000']);

        $this->product = $this->networkProduct(350.0, $this->storeA, priceOld: 400.0);
        $this->foreign = $this->networkProduct(200.0, $this->storeB);
    }

    /**
     * Флаг модуля хранится в storefront_settings.project_modules (type=json).
     * ModuleConfigService — singleton с мемоизацией, поэтому после записи
     * сбрасываем и кеш, и мемо.
     */
    private function setNetworkCatalog(bool $enabled, bool $preorders = false, bool $configurable = false): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => $enabled],
                    'preorders' => ['label' => 'Заявки', 'enabled' => $preorders],
                    'configurable_products' => ['label' => 'Вариативные товары', 'enabled' => $configurable],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
    }

    /**
     * Глобальная карточка (владелец — якорный магазин) с оффером в $store.
     */
    private function networkProduct(
        float $price,
        ?Store $store = null,
        ?float $priceOld = null,
        int $quantity = 5,
        ?Category $category = null,
    ): Product {
        $product = Product::factory()
            ->inCategory($category ?? $this->category)
            ->create([
                'store_id' => $this->anchorStore->id,
                'is_active' => true,
                'price' => 0,
                'quantity' => 0,
                'quantity_step' => 1,
            ]);

        if ($store !== null) {
            StoreProduct::create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'price' => $price,
                'price_old' => $priceOld,
                'quantity' => $quantity,
            ]);
        }

        return $product;
    }

    // ==================== 1. Дерево категорий ====================

    public function test_category_tree_leaf_products_use_store_offers(): void
    {
        $responseA = $this->getJson("/api/v1/stores/{$this->storeA->id}/categories?includeChildren=1&includeProducts=1");
        $responseA->assertOk();

        $nodeA = collect($responseA->json('data'))->firstWhere('id', $this->category->id);
        $this->assertNotNull($nodeA);
        $this->assertSame([$this->product->id], collect($nodeA['products'])->pluck('id')->all());
        $this->assertEquals(350.0, (float) $nodeA['products'][0]['price']);
        $this->assertEquals(400.0, (float) $nodeA['products'][0]['priceOld']);
        $this->assertSame(5, (int) $nodeA['products'][0]['stockQuantity']);

        $responseB = $this->getJson("/api/v1/stores/{$this->storeB->id}/categories?includeChildren=1&includeProducts=1");
        $responseB->assertOk();

        $nodeB = collect($responseB->json('data'))->firstWhere('id', $this->category->id);
        $this->assertSame([$this->foreign->id], collect($nodeB['products'])->pluck('id')->all());
        $this->assertEquals(200.0, (float) $nodeB['products'][0]['price']);
    }

    /**
     * products_count обязан считать ту же видимость, что и листинг: товар,
     * у которого остаток есть только в ЧУЖОМ магазине, в счётчик магазина
     * контекста не попадает (иначе тег категории показывается над пустым
     * списком товаров).
     */
    public function test_category_products_count_matches_store_listing(): void
    {
        $category = Category::factory()
            ->forStore($this->storeA)
            ->create(['is_active' => true, 'tag_text' => 'Новинки', 'tag_color' => '#00ff00']);

        $product = $this->networkProduct(150.0, $this->storeA, quantity: 0, category: $category);
        StoreProduct::create([
            'store_id' => $this->storeB->id,
            'product_id' => $product->id,
            'price' => 150.0,
            'quantity' => 7,
        ]);

        $response = $this->getJson("/api/v1/stores/{$this->storeA->id}/categories?includeChildren=1&includeProducts=1");
        $response->assertOk();

        $node = collect($response->json('data'))->firstWhere('id', $category->id);
        $this->assertNotNull($node);
        $this->assertSame([], collect($node['products'])->pluck('id')->all(), 'листинг категории пуст');
        $this->assertNull($node['tagText'], 'products_count посчитан по видимости чужого магазина');
    }

    // ==================== 2. price-range и фильтры ====================

    public function test_category_price_range_uses_store_offer_prices(): void
    {
        $this->networkProduct(900.0, $this->storeA);

        $this->getJson("/api/v1/stores/{$this->storeA->id}/categories/{$this->category->id}/price-range")
            ->assertOk()
            ->assertJson(['min' => 350, 'max' => 900]);

        $this->getJson("/api/v1/stores/{$this->storeB->id}/categories/{$this->category->id}/price-range")
            ->assertOk()
            ->assertJson(['min' => 200, 'max' => 200]);

        $filters = $this->getJson("/api/v1/stores/{$this->storeA->id}/categories/{$this->category->id}/filters");
        $filters->assertOk();
        $price = collect($filters->json('data'))->firstWhere('code', 'price');
        $this->assertSame(350, $price['min']);
        $this->assertSame(900, $price['max']);
    }

    // ==================== 3. Поиск по штрихкоду ====================

    public function test_search_by_barcode_uses_store_offer_price(): void
    {
        $this->product->update(['barcodes' => ['4600000000001']]);

        $withContext = $this->getJson("/api/v1/products/search/barcode/4600000000001?storeId={$this->storeA->id}");
        $withContext->assertOk();
        $this->assertEquals(350.0, (float) $withContext->json('data.price'));
        $this->assertSame(5, (int) $withContext->json('data.stockQuantity'));

        // Без контекста магазина карточка не должна отдавать нулевую цену.
        $withoutContext = $this->getJson('/api/v1/products/search/barcode/4600000000001');
        $withoutContext->assertOk();
        $this->assertEquals(350.0, (float) $withoutContext->json('data.price'));

        // В магазине без оффера товар не продаётся.
        $this->getJson("/api/v1/products/search/barcode/4600000000001?storeId={$this->storeB->id}")
            ->assertNotFound();
    }

    // ==================== 4. Товарные группы ====================

    public function test_product_group_products_use_group_store_offers(): void
    {
        $group = $this->productGroup([$this->product, $this->foreign], ['show_on_home' => true]);

        $products = $this->getJson("/api/v1/product-groups/{$group->id}/products");
        $products->assertOk();
        $this->assertSame([$this->product->id], collect($products->json('data'))->pluck('id')->all());
        $this->assertEquals(350.0, (float) $products->json('data.0.price'));
        $this->assertSame(5, (int) $products->json('data.0.stockQuantity'));

        $home = $this->getJson('/api/v1/product-groups?placement=home&withProducts=1');
        $home->assertOk();
        $node = collect($home->json('data'))->firstWhere('id', $group->id);
        $this->assertNotNull($node);
        $this->assertSame(1, (int) $node['productsCount'], 'счётчик группы по видимости магазина группы');
        $this->assertSame([$this->product->id], collect($node['products'])->pluck('id')->all());
        $this->assertEquals(350.0, (float) $node['products'][0]['price']);
    }

    /**
     * Страница подборки на витрине: сортировки и ценовые фильтры считаются
     * по цене оффера магазина группы, а не по нулевым колонкам карточки.
     */
    public function test_product_group_products_by_slug_sorts_and_filters_by_offer_price(): void
    {
        $cheap = $this->networkProduct(120.0, $this->storeA);
        $group = $this->productGroup([$this->product, $cheap, $this->foreign], ['slug' => 'hits']);

        $base = "/api/v1/stores/{$this->storeA->id}/product-groups/slug/{$group->slug}/products";

        $asc = $this->getJson($base.'?sort=price_asc');
        $asc->assertOk();
        $this->assertSame([$cheap->id, $this->product->id], collect($asc->json('data'))->pluck('id')->all());
        $this->assertEquals(120.0, (float) $asc->json('data.0.price'));

        $desc = $this->getJson($base.'?sort=price_desc');
        $desc->assertOk();
        $this->assertSame([$this->product->id, $cheap->id], collect($desc->json('data'))->pluck('id')->all());

        $range = $this->getJson($base.'?priceMin=200');
        $range->assertOk();
        $this->assertSame([$this->product->id], collect($range->json('data'))->pluck('id')->all());

        $discounted = $this->getJson($base.'?onlyDiscounted=1');
        $discounted->assertOk();
        $this->assertSame([$this->product->id], collect($discounted->json('data'))->pluck('id')->all());
    }

    // ==================== 5. Избранное ====================

    public function test_favorites_use_store_context_offers(): void
    {
        $user = User::factory()->create();
        Favorite::factory()->create(['user_id' => $user->id, 'product_id' => $this->product->id]);
        Favorite::factory()->create(['user_id' => $user->id, 'product_id' => $this->foreign->id]);

        $response = $this->actingAs($user)->getJson("/api/v1/favorites?storeId={$this->storeA->id}");
        $response->assertOk();
        $this->assertSame([$this->product->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertEquals(350.0, (float) $response->json('data.0.price'));
        $this->assertSame(5, (int) $response->json('data.0.stockQuantity'));
    }

    // ==================== 6. Секции меню ====================

    public function test_menu_sections_use_store_offers(): void
    {
        $this->storeA->update(['type' => 'restaurant']);
        $group = $this->productGroup([$this->product, $this->foreign], ['show_on_store_page' => true, 'position' => 0]);

        $response = $this->getJson("/api/v1/stores/{$this->storeA->id}/menu-sections");
        $response->assertOk();

        $sections = collect($response->json('data'));

        $groupSection = $sections->firstWhere('id', $group->id);
        $this->assertNotNull($groupSection);
        $this->assertSame([$this->product->id], collect($groupSection['products'])->pluck('id')->all());
        $this->assertEquals(350.0, (float) $groupSection['products'][0]['price']);

        $categorySection = $sections->firstWhere('id', $this->category->id);
        $this->assertNotNull($categorySection);
        $this->assertSame([$this->product->id], collect($categorySection['products'])->pluck('id')->all());
        $this->assertEquals(350.0, (float) $categorySection['products'][0]['price']);
    }

    // ==================== 7. Товары акции ====================

    public function test_promotion_products_use_promotion_store_offers(): void
    {
        $promotion = Promotion::factory()->create([
            'store_id' => $this->storeA->id,
            'discount_percent' => 10,
            'is_active' => true,
            // Роут-биндинг акций идёт по slug (getRouteKeyName), фабрика slug не задаёт.
            'slug' => 'promo-'.uniqid(),
        ]);
        $promotion->products()->attach([$this->product->id, $this->foreign->id]);

        $response = $this->getJson("/api/v1/promotions/{$promotion->slug}/products");
        $response->assertOk();

        $this->assertSame([$this->product->id], collect($response->json('data'))->pluck('id')->all());
        // База скидки — price_old оффера магазина акции (400), скидка 10%.
        $this->assertEquals(360.0, (float) $response->json('data.0.price'));
        $this->assertEquals(400.0, (float) $response->json('data.0.priceOld'));
    }

    // ==================== 8. Вариации карточки ====================

    public function test_variant_prices_and_stock_come_from_store_offers(): void
    {
        $this->setNetworkCatalog(true, configurable: true);

        [$parent, $first, $second] = $this->configurableFamily();

        StoreProduct::create([
            'store_id' => $this->storeA->id, 'product_id' => $first->id,
            'price' => 300.0, 'quantity' => 2,
        ]);
        StoreProduct::create([
            'store_id' => $this->storeA->id, 'product_id' => $second->id,
            'price' => 500.0, 'quantity' => 0,
        ]);

        $response = $this->getJson("/api/v1/products/{$parent->id}?storeId={$this->storeA->id}");
        $response->assertOk();

        $variants = collect($response->json('data.variants'))->keyBy('id');
        $this->assertEquals(300.0, (float) $variants[$first->id]['price']);
        $this->assertSame(2, (int) $variants[$first->id]['stockQuantity']);
        $this->assertSame('available', $variants[$first->id]['availability']);
        $this->assertEquals(500.0, (float) $variants[$second->id]['price']);
        $this->assertSame(0, (int) $variants[$second->id]['stockQuantity']);
        $this->assertSame('out_of_stock', $variants[$second->id]['availability']);

        // priceRange считается по офферам магазина и без вариаций без остатка.
        $this->assertEquals(300.0, (float) $response->json('data.priceRange.min'));
        $this->assertEquals(300.0, (float) $response->json('data.priceRange.max'));
    }

    /**
     * Вариативная семья без единого оффера в магазине контекста в этом
     * магазине не продаётся — 404, а не 200 с нулевой ценой.
     */
    public function test_variative_family_without_any_offer_returns_404(): void
    {
        $this->setNetworkCatalog(true, configurable: true);

        [$parent, $first] = $this->configurableFamily();

        StoreProduct::create([
            'store_id' => $this->storeA->id, 'product_id' => $first->id,
            'price' => 300.0, 'quantity' => 2,
        ]);

        $this->getJson("/api/v1/products/{$parent->id}?storeId={$this->storeB->id}")->assertNotFound();
        $this->getJson("/api/v1/products/{$first->id}?storeId={$this->storeB->id}")->assertNotFound();

        // В магазине с оффером вариации семья доступна.
        $this->getJson("/api/v1/products/{$parent->id}?storeId={$this->storeA->id}")->assertOk();
    }

    // ==================== 9-10. similar / related без контекста ====================

    public function test_similar_without_store_context_does_not_return_zero_prices(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->product->id}/similar");
        $response->assertOk();

        $items = collect($response->json('data'));
        $this->assertSame([$this->foreign->id], $items->pluck('id')->all());
        $this->assertEquals(200.0, (float) $items[0]['price']);
    }

    public function test_related_without_store_context_returns_offer_prices(): void
    {
        $parentCategory = Category::factory()->create(['is_active' => true]);
        $this->category->update(['parent_id' => $parentCategory->id]);

        $sibling = Category::factory()
            ->forStore($this->storeB)
            ->create(['is_active' => true, 'parent_id' => $parentCategory->id]);

        $siblingProduct = $this->networkProduct(275.0, $this->storeB, category: $sibling);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/related");
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data, 'related без контекста не должен падать в якорный магазин с пустой выдачей');
        $this->assertSame($sibling->id, $data[0]['id']);
        $this->assertSame([$siblingProduct->id], collect($data[0]['products'])->pluck('id')->all());
        $this->assertEquals(275.0, (float) $data[0]['products'][0]['price']);
    }

    // ==================== 12. Сравнение товаров ====================

    public function test_comparison_uses_store_offer_price(): void
    {
        $user = User::factory()->create();
        $comparison = Comparison::factory()->create([
            'user_id' => $user->id,
            'category_id' => $this->category->id,
        ]);
        ComparisonItem::factory()->create([
            'comparison_id' => $comparison->id,
            'product_id' => $this->product->id,
        ]);

        // С контекстом магазина — цена его оффера.
        $withContext = $this->actingAs($user)
            ->getJson("/api/v1/comparisons/{$this->category->id}?storeId={$this->storeA->id}");
        $withContext->assertOk();
        $this->assertEquals(350.0, (float) $withContext->json('data.products.0.price'));

        // Без контекста — лучший оффер сети, а не нулевая цена карточки.
        $withoutContext = $this->actingAs($user)->getJson("/api/v1/comparisons/{$this->category->id}");
        $withoutContext->assertOk();
        $this->assertEquals(350.0, (float) $withoutContext->json('data.products.0.price'));
    }

    // ==================== 13. Сметы ====================

    public function test_estimate_uses_store_offer_price(): void
    {
        $user = User::factory()->create();
        $estimate = Estimate::factory()->create(['user_id' => $user->id]);
        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
        ]);

        $withContext = $this->actingAs($user)
            ->getJson("/api/v1/estimates/{$estimate->id}?storeId={$this->storeA->id}");
        $withContext->assertOk();
        $this->assertEquals(350.0, (float) $withContext->json('data.items.0.product.price'));
        $this->assertSame(700, (int) $withContext->json('data.totalAmount'));

        // Без контекста — лучший оффер сети.
        $withoutContext = $this->actingAs($user)->getJson("/api/v1/estimates/{$estimate->id}");
        $withoutContext->assertOk();
        $this->assertEquals(350.0, (float) $withoutContext->json('data.items.0.product.price'));
        $this->assertSame(700, (int) $withoutContext->json('data.totalAmount'));

        // Публичная смета по share-токену — тоже не нулевая.
        $token = $this->actingAs($user)->postJson("/api/v1/estimates/{$estimate->id}/share")->json('shareToken');
        $shared = $this->getJson("/api/v1/estimates/shared/{$token}");
        $shared->assertOk();
        $this->assertEquals(350.0, (float) $shared->json('data.items.0.product.price'));
    }

    // ==================== 14. Лучший оффер сети ====================

    /**
     * Лучший оффер сети — сначала магазин, где товар реально есть: карточка
     * без контекста не должна отдавать stockQuantity=0, когда в соседнем
     * магазине остаток есть (пусть и дороже).
     */
    public function test_best_network_offer_prefers_store_with_stock(): void
    {
        $product = $this->networkProduct(500.0, $this->storeA, quantity: 4);
        StoreProduct::create([
            'store_id' => $this->storeB->id,
            'product_id' => $product->id,
            'price' => 300.0,
            'quantity' => 0,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();
        $this->assertEquals(500.0, (float) $response->json('data.price'));
        $this->assertSame(4, (int) $response->json('data.stockQuantity'));
    }

    /**
     * Догрузка офферов без контекста магазина: каждому товару пачки —
     * СВОЙ лучший оффер, и выбирает его SQL (по одной строке на товар),
     * а не PHP после вычитывания офферов всех магазинов. На полной сети
     * разница между «1 строка на товар» и «сотни» решающая, а публичная
     * смета по share-токену всегда идёт без контекста.
     */
    public function test_attach_without_context_loads_single_best_offer_per_product(): void
    {
        // Дешевле, но без остатка → выигрывает дорогой оффер с остатком.
        $stockWins = $this->networkProduct(500.0, $this->storeA, quantity: 4);
        StoreProduct::create([
            'store_id' => $this->storeB->id, 'product_id' => $stockWins->id,
            'price' => 300.0, 'quantity' => 0,
        ]);

        // Оба с остатком → выигрывает дешёвый.
        $priceWins = $this->networkProduct(500.0, $this->storeA, quantity: 4);
        StoreProduct::create([
            'store_id' => $this->storeB->id, 'product_id' => $priceWins->id,
            'price' => 300.0, 'quantity' => 7,
        ]);

        $products = Product::query()->whereIn('id', [$stockWins->id, $priceWins->id])->get();

        StoreOfferContext::attach($products);

        foreach ($products as $product) {
            $this->assertCount(
                1,
                $product->storeProducts,
                'на товар грузится ровно один оффер, а не все офферы сети'
            );
        }

        $first = $products->firstWhere('id', $stockWins->id);
        $this->assertEquals(500.0, (float) $first->storeContext()->price);
        $this->assertSame($this->storeA->id, (int) $first->storeContext()->store_id);

        $second = $products->firstWhere('id', $priceWins->id);
        $this->assertEquals(300.0, (float) $second->storeContext()->price);
        $this->assertSame($this->storeB->id, (int) $second->storeContext()->store_id);

        // Магазин оффера подгружен — его читает isStockIgnored().
        $this->assertTrue($second->storeContext()->relationLoaded('store'));
    }

    /**
     * Лучший оффер обязан выбираться на стороне SQL, а не в PHP после
     * вычитывания офферов всех магазинов: в полной сети это сотни строк
     * на товар, а публичная смета по share-токену всегда идёт без контекста.
     *
     * Тест проверяет ФОРМУ запроса (оконная функция row_number с отсечкой
     * laravel_row), потому что результат выбора у обеих реализаций одинаковый
     * — отличается только объём вычитки. Откат на ручной groupBy валит тест.
     */
    public function test_attach_without_context_picks_best_offer_in_sql(): void
    {
        $product = $this->networkProduct(900.0, $this->storeA, quantity: 1);

        // Ещё пять магазинов с офферами дешевле, но без остатка: победить
        // должен дорогой оффер с остатком, а вычитаться — одна строка.
        foreach (range(1, 5) as $index) {
            $store = Store::factory()->create(['code' => "attach-{$index}", 'is_active' => true]);
            StoreProduct::create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'price' => 100.0 * $index,
                'quantity' => 0,
            ]);
        }

        $products = Product::query()->whereKey($product->id)->get();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        StoreOfferContext::attach($products);

        $offerQueries = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'store_products'))->values();

        $this->assertCount(1, $offerQueries, 'офферы всей пачки тянутся одним запросом');
        $this->assertStringContainsString(
            'row_number',
            $offerQueries[0],
            'лучший оффер должен выбирать SQL (оконная функция), а не PHP после вычитки всех офферов'
        );
        $this->assertStringContainsString('laravel_row', $offerQueries[0], 'отсечка groupwise-max на месте');

        $loaded = $products->first();
        $this->assertCount(1, $loaded->storeProducts, 'на товар вычитана ровно одна строка офферов');
        $this->assertEquals(900.0, (float) $loaded->storeContext()->price);
        $this->assertSame($this->storeA->id, (int) $loaded->storeContext()->store_id);
    }

    /**
     * Тот же выбор на публичной ручке без авторизации (там контекста нет
     * никогда): у каждой позиции сметы своя лучшая цена.
     */
    public function test_shared_estimate_without_context_uses_best_offer_per_item(): void
    {
        $stockWins = $this->networkProduct(500.0, $this->storeA, quantity: 4);
        StoreProduct::create([
            'store_id' => $this->storeB->id, 'product_id' => $stockWins->id,
            'price' => 300.0, 'quantity' => 0,
        ]);

        $priceWins = $this->networkProduct(500.0, $this->storeA, quantity: 4);
        StoreProduct::create([
            'store_id' => $this->storeB->id, 'product_id' => $priceWins->id,
            'price' => 300.0, 'quantity' => 7,
        ]);

        $user = User::factory()->create();
        $estimate = Estimate::factory()->create(['user_id' => $user->id]);

        foreach ([$stockWins, $priceWins] as $product) {
            EstimateItem::factory()->create([
                'estimate_id' => $estimate->id,
                'product_id' => $product->id,
                'quantity' => 1,
            ]);
        }

        $token = $this->actingAs($user)->postJson("/api/v1/estimates/{$estimate->id}/share")->json('shareToken');

        $shared = $this->getJson("/api/v1/estimates/shared/{$token}");
        $shared->assertOk();

        $prices = collect($shared->json('data.items'))
            ->mapWithKeys(fn (array $item) => [(int) $item['product']['id'] => (float) $item['product']['price']]);

        $this->assertEquals(500.0, $prices[$stockWins->id]);
        $this->assertEquals(300.0, $prices[$priceWins->id]);
        $this->assertSame(800, (int) $shared->json('data.totalAmount'));
    }

    /**
     * Оффер на ВЫКЛЮЧЕННОЙ вариации доступности семье не даёт: карточка всё
     * равно отдала бы price=0 и пустой variants.
     */
    public function test_offer_on_inactive_variant_does_not_open_family(): void
    {
        $this->setNetworkCatalog(true, configurable: true);

        [$parent, $first] = $this->configurableFamily();
        $first->update(['is_active' => false]);

        StoreProduct::create([
            'store_id' => $this->storeA->id, 'product_id' => $first->id,
            'price' => 300.0, 'quantity' => 2,
        ]);

        $this->getJson("/api/v1/products/{$parent->id}?storeId={$this->storeA->id}")->assertNotFound();
    }

    // ==================== 15. Предзаказный гейт ====================

    /**
     * Новые EXISTS-скоупы обязаны уважать модуль предзаказов так же, как
     * листинг: товар с оффером и нулевым остатком остаётся видимым
     * (счётчик категории и избранное).
     */
    public function test_preorders_keep_zero_stock_offer_visible_in_counters_and_favorites(): void
    {
        $this->setNetworkCatalog(true, preorders: true);

        $category = Category::factory()
            ->forStore($this->storeA)
            ->create(['is_active' => true, 'tag_text' => 'Предзаказ', 'tag_color' => '#0000ff']);

        $product = $this->networkProduct(250.0, $this->storeA, quantity: 0, category: $category);

        // Счётчик категории (products_count → tagText) считает товар видимым.
        $categories = $this->getJson("/api/v1/stores/{$this->storeA->id}/categories?includeChildren=1&includeProducts=1");
        $categories->assertOk();
        $node = collect($categories->json('data'))->firstWhere('id', $category->id);
        $this->assertSame([$product->id], collect($node['products'])->pluck('id')->all());
        $this->assertSame('Предзаказ', $node['tagText']);

        // Избранное показывает его же с ценой оффера.
        $user = User::factory()->create();
        Favorite::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

        $favorites = $this->actingAs($user)->getJson("/api/v1/favorites?storeId={$this->storeA->id}");
        $favorites->assertOk();
        $this->assertSame([$product->id], collect($favorites->json('data'))->pluck('id')->all());
        $this->assertEquals(250.0, (float) $favorites->json('data.0.price'));
        $this->assertTrue($favorites->json('data.0.isPreorder'));
    }

    // ==================== 11. Легаси-режим ====================

    /**
     * Флаг выключен — все те же поверхности работают на колонках products,
     * контекст магазина не резолвится вообще.
     */
    public function test_legacy_mode_surfaces_are_unchanged(): void
    {
        $this->setNetworkCatalog(false);

        $category = Category::factory()
            ->forStore($this->storeA)
            ->create(['is_active' => true, 'tag_text' => 'Тег', 'tag_color' => '#000000']);

        $legacyProduct = Product::factory()->inCategory($category)->create([
            'store_id' => $this->storeA->id,
            'is_active' => true,
            'price' => 700,
            'quantity' => 10,
            'quantity_step' => 1,
            'barcodes' => ['4600000000009'],
        ]);

        // Дерево категорий: счётчик и товары из колонок products.
        $categories = $this->getJson("/api/v1/stores/{$this->storeA->id}/categories?includeChildren=1&includeProducts=1");
        $categories->assertOk();
        $node = collect($categories->json('data'))->firstWhere('id', $category->id);
        $this->assertSame('Тег', $node['tagText']);
        $this->assertSame([$legacyProduct->id], collect($node['products'])->pluck('id')->all());
        $this->assertEquals(700.0, (float) $node['products'][0]['price']);

        // price-range.
        $this->getJson("/api/v1/stores/{$this->storeA->id}/categories/{$category->id}/price-range")
            ->assertOk()
            ->assertJson(['min' => 700, 'max' => 700]);

        // Штрихкод: storeId в легаси ни на что не влияет.
        $barcode = $this->getJson("/api/v1/products/search/barcode/4600000000009?storeId={$this->storeB->id}");
        $barcode->assertOk();
        $this->assertEquals(700.0, (float) $barcode->json('data.price'));

        // Товарная группа.
        $group = ProductGroup::factory()->create([
            'store_id' => $this->storeA->id,
            'is_active' => true,
            'show_on_home' => true,
        ]);
        ProductGroupItem::factory()->create([
            'product_group_id' => $group->id,
            'product_id' => $legacyProduct->id,
            'position' => 0,
        ]);
        $home = $this->getJson('/api/v1/product-groups?placement=home&withProducts=1');
        $home->assertOk();
        $groupNode = collect($home->json('data'))->firstWhere('id', $group->id);
        $this->assertSame(1, (int) $groupNode['productsCount']);
        $this->assertEquals(700.0, (float) $groupNode['products'][0]['price']);

        // Акция: на отдельном товаре, чтобы её скидка не влияла на цену
        // $legacyProduct в остальных проверках (ProductResource применяет
        // активную акцию к цене карточки).
        $promoProduct = Product::factory()->inCategory($category)->create([
            'store_id' => $this->storeA->id,
            'is_active' => true,
            'price' => 500,
            'quantity' => 4,
            'quantity_step' => 1,
        ]);
        $promotion = Promotion::factory()->create([
            'store_id' => $this->storeA->id,
            'discount_percent' => 10,
            'is_active' => true,
            // Роут-биндинг акций идёт по slug (getRouteKeyName), фабрика slug не задаёт.
            'slug' => 'promo-'.uniqid(),
        ]);
        $promotion->products()->attach($promoProduct->id);
        $promo = $this->getJson("/api/v1/promotions/{$promotion->slug}/products");
        $promo->assertOk();
        $this->assertEquals(450.0, (float) $promo->json('data.0.price'));

        // Избранное.
        $user = User::factory()->create();
        Favorite::factory()->create(['user_id' => $user->id, 'product_id' => $legacyProduct->id]);
        $favorites = $this->actingAs($user)->getJson("/api/v1/favorites?storeId={$this->storeB->id}");
        $favorites->assertOk();
        $this->assertSame([$legacyProduct->id], collect($favorites->json('data'))->pluck('id')->all());
        $this->assertEquals(700.0, (float) $favorites->json('data.0.price'));
    }

    /**
     * Товарная группа магазина A с переданными товарами.
     *
     * @param  array<int, Product>  $products
     * @param  array<string, mixed>  $attributes
     */
    private function productGroup(array $products, array $attributes = []): ProductGroup
    {
        $group = ProductGroup::factory()->create(array_merge([
            'store_id' => $this->storeA->id,
            'is_active' => true,
        ], $attributes));

        foreach ($products as $position => $product) {
            ProductGroupItem::factory()->create([
                'product_group_id' => $group->id,
                'product_id' => $product->id,
                'position' => $position,
            ]);
        }

        return $group;
    }

    /**
     * Вариативный родитель + две активные вариации (без офферов).
     *
     * @return array{0: Product, 1: Product, 2: Product}
     */
    private function configurableFamily(): array
    {
        $parent = Product::factory()->inCategory($this->category)->create([
            'store_id' => $this->anchorStore->id,
            'is_active' => true,
            'is_configurable' => true,
            'price' => 0,
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $variants = [];

        foreach ([true, false] as $isDefault) {
            $variants[] = Product::factory()->create([
                'store_id' => $this->anchorStore->id,
                'parent_id' => $parent->id,
                'is_active' => true,
                'is_default_variant' => $isDefault,
                'price' => 0,
                'quantity' => 0,
                'quantity_step' => 1,
            ]);
        }

        return [$parent, $variants[0], $variants[1]];
    }
}
