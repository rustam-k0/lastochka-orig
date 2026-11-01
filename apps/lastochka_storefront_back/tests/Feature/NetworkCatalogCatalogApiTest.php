<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Сетевой каталог (network_catalog) на каталожных ручках storefront:
 * карточка товара глобальна (владелец — якорный store), а видимость,
 * цена и остаток в выдаче берутся из store_products магазина запроса.
 *
 * API-контракт ручек не меняется — меняется только источник данных.
 */
class NetworkCatalogCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $anchorStore;

    private Store $storeA;

    private Store $storeB;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Якорный магазин — владелец глобальных карточек (минимальный id).
        $this->anchorStore = Store::factory()->create(['code' => 'anchor', 'is_active' => true]);
        $this->storeA = Store::factory()->create(['code' => 'cat-a', 'is_active' => true]);
        $this->storeB = Store::factory()->create(['code' => 'cat-b', 'is_active' => true]);

        $this->setNetworkCatalog(true);

        $this->category = Category::factory()
            ->forStore($this->storeA)
            ->forStore($this->storeB)
            ->create(['is_active' => true]);

        $this->product = Product::factory()
            ->inCategory($this->category)
            ->create([
                'store_id' => $this->anchorStore->id,
                'is_active' => true,
                'price' => 0,
                'quantity' => 0,
                'quantity_step' => 1,
            ]);

        // Оффер только в магазине A.
        StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $this->product->id,
            'price' => 350.0,
            'price_old' => 400.0,
            'quantity' => 5,
        ]);
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

    private function endpoint(Store $store): string
    {
        return "/api/v1/stores/{$store->id}/categories/{$this->category->id}/products";
    }

    public function test_catalog_shows_product_with_store_price_in_store_with_offer(): void
    {
        $response = $this->getJson($this->endpoint($this->storeA));

        $response->assertOk();
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertEquals(350.0, (float) $items[0]['price']);
        $this->assertEquals(400.0, (float) $items[0]['priceOld']);
        $this->assertEquals(5, (int) $items[0]['stockQuantity']);
    }

    public function test_catalog_hides_product_in_store_without_offer(): void
    {
        $response = $this->getJson($this->endpoint($this->storeB));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_zero_quantity_offer_is_not_purchasable(): void
    {
        StoreProduct::where('store_id', $this->storeA->id)->update(['quantity' => 0]);

        $response = $this->getJson($this->endpoint($this->storeA));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    /**
     * Контракт из ревью T10: строка store_products с price=0 (создана
     * stock-событием раньше цены) оффером не считается.
     */
    public function test_zero_price_offer_is_not_an_offer(): void
    {
        StoreProduct::where('store_id', $this->storeA->id)->update(['price' => 0]);

        $response = $this->getJson($this->endpoint($this->storeA));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_inactive_offer_is_hidden(): void
    {
        StoreProduct::where('store_id', $this->storeA->id)->update(['is_active' => false]);

        $response = $this->getJson($this->endpoint($this->storeA));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    /**
     * При включённых предзаказах листинг обязан показывать товары без остатка
     * (как в легаси), а не резать их по остатку оффера.
     */
    public function test_zero_quantity_offer_is_visible_when_preorders_enabled(): void
    {
        $this->setNetworkCatalog(true, preorders: true);
        StoreProduct::where('store_id', $this->storeA->id)->update(['quantity' => 0]);

        $response = $this->getJson($this->endpoint($this->storeA));

        $response->assertOk();
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($this->product->id, $items[0]['id']);
        $this->assertTrue($items[0]['isPreorder']);
    }

    /**
     * Магазин с ignore_stock отдаёт товар без остатка и при выключенных
     * предзаказах — режим «не учитывать остатки» берётся у магазина оффера,
     * а не у якорного владельца карточки.
     */
    public function test_offer_store_ignore_stock_keeps_product_visible(): void
    {
        $this->storeA->update(['ignore_stock' => true]);
        StoreProduct::where('store_id', $this->storeA->id)->update(['quantity' => 0]);

        $response = $this->getJson($this->endpoint($this->storeA));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * Флаг выключен — контекст магазина не резолвится вообще: ни активный
     * адрес авторизованного пользователя, ни явный storeId не меняют выдачу
     * similar/related. Проверка требования «флаг off = байт-в-байт легаси».
     */
    public function test_legacy_mode_ignores_store_context_in_similar_and_related(): void
    {
        $this->setNetworkCatalog(false);

        $parent = Category::factory()->create(['is_active' => true]);
        $categoryA = Category::factory()->forStore($this->storeA)->create(['is_active' => true, 'parent_id' => $parent->id]);
        $categoryB = Category::factory()->forStore($this->storeB)->create(['is_active' => true, 'parent_id' => $parent->id]);

        $anchor = Product::factory()->inCategory($categoryA)->create([
            'store_id' => $this->storeB->id, 'is_active' => true, 'price' => 100, 'quantity' => 10,
        ]);
        // Похожий товар в другом магазине: легаси-выдача его НЕ показывает
        // (main-фикс cadb9412: similar скоупится магазином исходного товара).
        $foreign = Product::factory()->inCategory($categoryA)->create([
            'store_id' => $this->storeA->id, 'is_active' => true, 'price' => 200, 'quantity' => 10,
        ]);
        // Похожий товар того же магазина, что и исходный: он и есть легаси-выдача.
        $sameStore = Product::factory()->inCategory($categoryA)->create([
            'store_id' => $this->storeB->id, 'is_active' => true, 'price' => 250, 'quantity' => 10,
        ]);
        // Товар sibling-категории магазина B — виден только через related
        // магазина карточки ($storeB).
        $siblingProduct = Product::factory()->inCategory($categoryB)->create([
            'store_id' => $this->storeB->id, 'is_active' => true, 'price' => 300, 'quantity' => 10,
        ]);
        $categoryB->stores()->syncWithoutDetaching([$this->storeB->id => ['position' => 0, 'is_active' => true]]);

        // Активный адрес пользователя резолвится в магазин A — если бы гейта
        // не было, similar/related переключились бы на него.
        $user = User::factory()->create();
        Address::factory()->active()->create([
            'user_id' => $user->id, 'latitude' => 55.7558, 'longitude' => 37.6173,
        ]);
        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            ['id' => 1, 'code' => $this->storeA->code, 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        // similar: и с адресом, и с явным storeId выдача одна и та же -
        // магазин исходного товара (контекст в легаси игнорируется).
        foreach (['', '?storeId='.$this->storeA->id] as $suffix) {
            $similar = $this->actingAs($user)->getJson("/api/v1/products/{$anchor->id}/similar{$suffix}");
            $similar->assertOk();
            $this->assertSame(
                [$sameStore->id],
                collect($similar->json('data'))->pluck('id')->all(),
                "similar не должен зависеть от контекста магазина при выключенном флаге ({$suffix})"
            );
        }

        // related: магазин берётся у карточки ($storeB), а не из контекста.
        foreach (['', '?storeId='.$this->storeA->id] as $suffix) {
            $related = $this->actingAs($user)->getJson("/api/v1/products/{$anchor->id}/related{$suffix}");
            $related->assertOk();
            $data = $related->json('data');
            $this->assertCount(1, $data, "related не должен зависеть от контекста магазина при выключенном флаге ({$suffix})");
            $this->assertSame($categoryB->id, $data[0]['id']);
            $this->assertSame([$siblingProduct->id], collect($data[0]['products'])->pluck('id')->all());
        }
    }

    /**
     * Явный storeId без оффера — карточка в этом магазине не продаётся:
     * 404, а не 200 с нулевой ценой.
     */
    public function test_product_show_with_store_without_offer_returns_404(): void
    {
        $this->getJson("/api/v1/products/{$this->product->id}?storeId={$this->storeB->id}")
            ->assertNotFound();
    }

    /**
     * Строка store_products с price=0 оффером не считается и на карточке.
     */
    public function test_product_show_with_zero_price_offer_returns_404(): void
    {
        StoreProduct::where('store_id', $this->storeA->id)->update(['price' => 0]);

        $this->getJson("/api/v1/products/{$this->product->id}?storeId={$this->storeA->id}")
            ->assertNotFound();
    }

    /**
     * Deep-link на вариацию: оффер лежит у родителя, у самой вариации его нет.
     * Отдаём родителя с предвыбранным вариантом, а не 404.
     */
    public function test_variant_without_own_offer_returns_parent(): void
    {
        $this->setNetworkCatalog(true, configurable: true);

        [$parent, $child] = $this->configurableFamily();

        StoreProduct::create([
            'store_id' => $this->storeA->id, 'product_id' => $parent->id,
            'price' => 500.0, 'quantity' => 4,
        ]);

        $response = $this->getJson("/api/v1/products/{$child->id}?storeId={$this->storeA->id}");

        $response->assertOk();
        $this->assertSame($parent->id, $response->json('data.id'));
        $this->assertSame($child->id, $response->json('data.selectedVariantId'));
        $this->assertEquals(500.0, (float) $response->json('data.price'));
    }

    /**
     * Обратный случай: оффер у вариации, у вариативного родителя своего нет.
     * Карточка родителя всё равно доступна.
     */
    public function test_configurable_parent_without_own_offer_is_available(): void
    {
        $this->setNetworkCatalog(true, configurable: true);

        [$parent, $child] = $this->configurableFamily();

        StoreProduct::create([
            'store_id' => $this->storeA->id, 'product_id' => $child->id,
            'price' => 450.0, 'quantity' => 4,
        ]);

        $response = $this->getJson("/api/v1/products/{$parent->id}?storeId={$this->storeA->id}");

        $response->assertOk();
        $this->assertSame($parent->id, $response->json('data.id'));
    }

    /**
     * Вариативный родитель + одна активная вариация (без офферов).
     *
     * @return array{0: Product, 1: Product}
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

        $child = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'parent_id' => $parent->id,
            'is_active' => true,
            'is_default_variant' => true,
            'price' => 0,
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        return [$parent, $child];
    }

    /**
     * Сортировки и ценовые фильтры в сетевом режиме считаются по цене оффера
     * магазина, а не по нулевым колонкам products.
     */
    public function test_sorting_and_price_filters_use_offer_price(): void
    {
        $cheap = $this->networkProduct(price: 100.0, priceOld: 150.0);
        $expensive = $this->networkProduct(price: 900.0);

        $asc = $this->getJson($this->endpoint($this->storeA).'?sort=price_asc');
        $asc->assertOk();
        $this->assertSame(
            [$cheap->id, $this->product->id, $expensive->id],
            collect($asc->json('data'))->pluck('id')->all()
        );
        // join оффера строго 1:1 (UNIQUE(store_id, product_id)) — пагинация
        // не задваивает и считает столько же, сколько countOnly.
        $this->assertSame(3, $asc->json('total'));
        $this->assertFalse($asc->json('hasMore'));

        $paged = $this->getJson($this->endpoint($this->storeA).'?sort=price_asc&perPage=2');
        $paged->assertOk();
        $this->assertCount(2, $paged->json('data'));
        $this->assertSame(3, $paged->json('total'));
        $this->assertTrue($paged->json('hasMore'));

        $desc = $this->getJson($this->endpoint($this->storeA).'?sort=price_desc');
        $desc->assertOk();
        $this->assertSame(
            [$expensive->id, $this->product->id, $cheap->id],
            collect($desc->json('data'))->pluck('id')->all()
        );

        $discount = $this->getJson($this->endpoint($this->storeA).'?sort=discount');
        $discount->assertOk();
        // Скидка 33% у $cheap против 12.5% у якорного товара — он первый.
        $this->assertSame($cheap->id, $discount->json('data.0.id'));

        $range = $this->getJson($this->endpoint($this->storeA).'?priceMin=200&priceMax=500');
        $range->assertOk();
        $this->assertSame([$this->product->id], collect($range->json('data'))->pluck('id')->all());

        $discounted = $this->getJson($this->endpoint($this->storeA).'?onlyDiscounted=1');
        $discounted->assertOk();
        $this->assertEqualsCanonicalizing(
            [$cheap->id, $this->product->id],
            collect($discounted->json('data'))->pluck('id')->all()
        );

        $countOnly = $this->getJson($this->endpoint($this->storeA).'?countOnly=1');
        $countOnly->assertOk();
        $this->assertSame(3, $countOnly->json('total'));
    }

    /**
     * Товар сети с оффером в магазине A.
     */
    private function networkProduct(float $price, ?float $priceOld = null, int $quantity = 5): Product
    {
        $product = Product::factory()
            ->inCategory($this->category)
            ->create([
                'store_id' => $this->anchorStore->id,
                'is_active' => true,
                'price' => 0,
                'quantity' => 0,
                'quantity_step' => 1,
            ]);

        StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'price' => $price,
            'price_old' => $priceOld,
            'quantity' => $quantity,
        ]);

        return $product;
    }

    public function test_product_show_with_store_context(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->product->id}?storeId={$this->storeA->id}");

        $response->assertOk();
        $this->assertEquals(350.0, (float) $response->json('data.price'));
        $this->assertEquals(400.0, (float) $response->json('data.priceOld'));
        $this->assertEquals(5, (int) $response->json('data.stockQuantity'));
    }

    /**
     * Без контекста магазина карточка остаётся доступной (404 по остатку
     * карточки в сетевом режиме не выносим — остаток живёт в store_products),
     * а цена берётся из лучшего оффера сети: нулевые колонки якорной
     * карточки клиенту не отдаём.
     */
    public function test_product_show_without_store_context_is_not_404(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->product->id}");

        $response->assertOk();
        $this->assertEquals(350.0, (float) $response->json('data.price'));
        $this->assertEquals(400.0, (float) $response->json('data.priceOld'));
        $this->assertSame(5, (int) $response->json('data.stockQuantity'));
    }

    public function test_similar_respects_store_context(): void
    {
        $other = Product::factory()
            ->inCategory($this->category)
            ->create([
                'store_id' => $this->anchorStore->id,
                'is_active' => true,
                'price' => 0,
                'quantity' => 0,
                'quantity_step' => 1,
            ]);

        StoreProduct::create([
            'store_id' => $this->storeB->id,
            'product_id' => $other->id,
            'price' => 120.0,
            'quantity' => 3,
        ]);

        // В магазине A оффера у $other нет — похожих товаров быть не должно.
        $response = $this->getJson("/api/v1/products/{$this->product->id}/similar?storeId={$this->storeA->id}");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));

        // В магазине B оффер есть — товар виден с ценой магазина B.
        $response = $this->getJson("/api/v1/products/{$this->product->id}/similar?storeId={$this->storeB->id}");

        $response->assertOk();
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($other->id, $items[0]['id']);
        $this->assertEquals(120.0, (float) $items[0]['price']);
    }

    /**
     * Легаси-режим (флаг выключен) не должен замечать разницы: товар живёт
     * в своём магазине, цены/остатки — колонки products.
     */
    public function test_legacy_mode_keeps_store_column_semantics(): void
    {
        $this->setNetworkCatalog(false);

        $legacyProduct = Product::factory()
            ->inCategory($this->category)
            ->create([
                'store_id' => $this->storeA->id,
                'is_active' => true,
                'price' => 700,
                'quantity' => 10,
                'quantity_step' => 1,
            ]);

        $response = $this->getJson($this->endpoint($this->storeA));

        $response->assertOk();
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($legacyProduct->id, $items[0]['id']);
        $this->assertEquals(700.0, (float) $items[0]['price']);
    }

    public function test_filters_use_offer_price_for_bounds_and_counts(): void
    {
        $cheap = $this->networkProduct(price: 99.5);
        $expensive = $this->networkProduct(price: 900.4);

        foreach ([[$cheap, 11, 'abb', 'ABB'], [$expensive, 10, 'schneider', 'Schneider'], [$this->product, 10, 'schneider', 'Schneider']] as [$product, $valueId, $code, $name]) {
            \App\Models\ProductAttribute::create([
                'product_id' => $product->id,
                'code' => 'manufacturer',
                'name' => 'Производитель',
                'value' => $name,
                'attribute_value_id' => $valueId,
                'attribute_value_code' => $code,
                'position' => 0,
            ]);
        }

        $filters = "/api/v1/stores/{$this->storeA->id}/categories/{$this->category->id}/filters";

        // Цена карточки нулевая — границы берутся из офферов магазина и
        // округляются наружу, чтобы крайние товары попадали в диапазон.
        $price = collect($this->getJson($filters)->assertOk()->json('data'))->firstWhere('code', 'price');
        $this->assertSame(99, $price['min']);
        $this->assertSame(901, $price['max']);

        $manufacturer = collect($this->getJson($filters . '?priceMin=300')->assertOk()->json('data'))
            ->firstWhere('code', 'manufacturer');
        $counts = collect($manufacturer['values'])->pluck('count', 'code')->all();
        $this->assertSame(['abb' => 0, 'schneider' => 2], $counts);

        // В магазине B офферов нет — ни цен, ни значений.
        $storeB = $this->getJson("/api/v1/stores/{$this->storeB->id}/categories/{$this->category->id}/filters")
            ->assertOk()
            ->json('data');
        $this->assertSame([['code' => 'price', 'name' => 'Цена', 'type' => 'range', 'min' => 0, 'max' => 0]], $storeB);
    }
}
