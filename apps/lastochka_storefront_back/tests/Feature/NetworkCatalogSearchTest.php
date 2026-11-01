<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use App\Services\Settings\ModuleConfigService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Сетевой каталог (network_catalog) в поиске.
 *
 * Документ Meilisearch остаётся глобальным (одна карточка на товар, store_id —
 * якорный магазин, price/quantity нулевые), поэтому в сетевом режиме поиск:
 * (а) не фильтрует по store_id/price/in_stock в Meili — это отсекло бы всю выдачу;
 * (б) считает доступность и цену по офферам store_products;
 * (в) store-scoped выдача (/search/stores/{store}/products) видит только офферы
 *     этого магазина, глобальная (/search/products) — магазин активного адреса,
 *     а без адреса лучший оффер сети.
 * При выключенном флаге поведение прежнее (покрыто tests/Feature/Search).
 */
class NetworkCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    private SearchService $searchService;

    private Store $anchorStore;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Product::unsetEventDispatcher();

        $this->searchClient = $this->app->make(SearchClientInterface::class);
        $this->searchService = $this->app->make(SearchService::class);
        $this->searchClient->resetIndex();

        // Якорный магазин — владелец глобальных карточек (минимальный id).
        $this->anchorStore = Store::factory()->create(['code' => 'anchor', 'is_active' => true]);
        $this->storeA = Store::factory()->create(['code' => 'search-a', 'is_active' => true]);
        $this->storeB = Store::factory()->create(['code' => 'search-b', 'is_active' => true]);

        $this->setNetworkCatalog(true);
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
     * Глобальная карточка (владелец — якорный магазин) с нулевыми ценой
     * и остатком: и то, и другое живёт в store_products.
     */
    private function networkProduct(string $title): Product
    {
        $product = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'is_active' => true,
            'title' => $title,
            'price' => 0,
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $this->searchService->indexProduct($product);

        return $product;
    }

    private function offer(Product $product, Store $store, float $price, int $quantity = 5, ?float $priceOld = null): StoreProduct
    {
        return StoreProduct::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'price' => $price,
            'price_old' => $priceOld,
            'quantity' => $quantity,
        ]);
    }

    // ==================== 1. Поиск в магазине ====================

    public function test_store_scoped_search_returns_store_price_and_hides_missing_offers(): void
    {
        $withOffer = $this->networkProduct('Виски сетевой');
        $withoutOffer = $this->networkProduct('Виски без оффера');

        $this->offer($withOffer, $this->storeA, 999.0, quantity: 3, priceOld: 1200.0);

        $response = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query=".urlencode('Виски'));

        $response->assertOk();
        $items = collect($response->json('data'));

        $this->assertTrue($items->contains(fn ($i) => (int) $i['id'] === $withOffer->id));
        $this->assertFalse(
            $items->contains(fn ($i) => (int) $i['id'] === $withoutOffer->id),
            'товар без оффера магазина скрыт'
        );
        $this->assertSame(1, $response->json('total'));

        $item = $items->firstWhere('id', $withOffer->id);
        $this->assertEquals(999.0, (float) $item['price']);
        $this->assertEquals(1200.0, (float) $item['priceOld']);
        $this->assertSame(3, (int) $item['stockQuantity']);
    }

    /**
     * Оффер чужого магазина товар в этой выдаче не показывает — иначе поиск
     * магазина отдавал бы карточку с ценой соседнего магазина.
     */
    public function test_store_scoped_search_ignores_foreign_store_offers(): void
    {
        $product = $this->networkProduct('Виски односолодовый');
        $this->offer($product, $this->storeB, 500.0);

        $response = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query=".urlencode('Виски'));

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('total'));

        $foreign = $this->getJson("/api/v1/search/stores/{$this->storeB->id}/products?query=".urlencode('Виски'));
        $foreign->assertOk();
        $this->assertSame([$product->id], collect($foreign->json('data'))->pluck('id')->all());
        $this->assertEquals(500.0, (float) $foreign->json('data.0.price'));
    }

    /**
     * Нулевой остаток оффера прячет товар из выдачи магазина (предзаказы off),
     * даже если в соседнем магазине остаток есть.
     */
    public function test_store_scoped_search_hides_out_of_stock_offer(): void
    {
        $product = $this->networkProduct('Виски выдержанный');
        $this->offer($product, $this->storeA, 700.0, quantity: 0);
        $this->offer($product, $this->storeB, 700.0, quantity: 4);

        $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query=".urlencode('Виски'))
            ->assertOk()
            ->assertJson(['total' => 0, 'data' => []]);

        $this->getJson("/api/v1/search/stores/{$this->storeB->id}/products?query=".urlencode('Виски'))
            ->assertOk()
            ->assertJson(['total' => 1]);
    }

    /**
     * Сортировка по цене считается по офферу магазина: products.price в сети
     * всегда 0, и легаси-сортировка дала бы произвольный порядок.
     */
    public function test_store_scoped_search_sorts_by_store_offer_price(): void
    {
        $cheapInA = $this->networkProduct('Виски дешёвый');
        $expensiveInA = $this->networkProduct('Виски дорогой');

        $this->offer($cheapInA, $this->storeA, 100.0);
        $this->offer($expensiveInA, $this->storeA, 900.0);
        // В магазине B цены зеркальные — порядок обязан отличаться.
        $this->offer($cheapInA, $this->storeB, 900.0);
        $this->offer($expensiveInA, $this->storeB, 100.0);

        $query = urlencode('Виски');

        $ascA = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query={$query}&sort=price_asc");
        $ascA->assertOk();
        $this->assertSame([$cheapInA->id, $expensiveInA->id], collect($ascA->json('data'))->pluck('id')->all());

        $descA = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query={$query}&sort=price_desc");
        $descA->assertOk();
        $this->assertSame([$expensiveInA->id, $cheapInA->id], collect($descA->json('data'))->pluck('id')->all());

        $ascB = $this->getJson("/api/v1/search/stores/{$this->storeB->id}/products?query={$query}&sort=price_asc");
        $ascB->assertOk();
        $this->assertSame([$expensiveInA->id, $cheapInA->id], collect($ascB->json('data'))->pluck('id')->all());
    }

    /**
     * Остальные ветки сортировки в сетевом режиме считаются по офферу
     * (discount) либо по колонкам карточки (popular/newest) и не должны
     * ломаться на join'е оффера.
     */
    public function test_store_scoped_search_supports_other_sorts(): void
    {
        $discounted = $this->networkProduct('Виски со скидкой');
        $plain = $this->networkProduct('Виски без скидки');

        $this->offer($discounted, $this->storeA, 500.0, priceOld: 1000.0);
        $this->offer($plain, $this->storeA, 500.0);

        $query = urlencode('Виски');

        $byDiscount = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query={$query}&sort=discount");
        $byDiscount->assertOk();
        $this->assertSame([$discounted->id, $plain->id], collect($byDiscount->json('data'))->pluck('id')->all());

        foreach (['popular', 'newest'] as $sort) {
            $response = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query={$query}&sort={$sort}");
            $response->assertOk();
            $this->assertEqualsCanonicalizing(
                [$discounted->id, $plain->id],
                collect($response->json('data'))->pluck('id')->all(),
                "сортировка {$sort} не должна терять товары"
            );
        }
    }

    /**
     * Пагинация и hasMore считаются по товарам, доступным в магазине, а не по
     * всем хитам Meili.
     */
    public function test_store_scoped_search_paginates_over_store_offers(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $product = $this->networkProduct("Виски номер {$i}");
            $this->offer($product, $this->storeA, 100.0 * $i);
        }

        // Ещё один хит по тому же запросу, но без оффера магазина A.
        $this->offer($this->networkProduct('Виски номер 4'), $this->storeB, 400.0);

        $query = urlencode('Виски номер');

        $first = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query={$query}&perPage=2&page=1");
        $first->assertOk();
        $this->assertSame(3, $first->json('total'));
        $this->assertCount(2, $first->json('data'));
        $this->assertTrue($first->json('hasMore'));

        $second = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query={$query}&perPage=2&page=2");
        $second->assertOk();
        $this->assertSame(3, $second->json('total'));
        $this->assertCount(1, $second->json('data'));
        $this->assertFalse($second->json('hasMore'));
    }

    /**
     * onlyDiscounted в сети считается по офферу: has_discount документа
     * посчитан по якорной карточке (price_old пуст) и всегда false, поэтому
     * Meili-фильтр по нему отсекал бы всю выдачу.
     */
    public function test_store_scoped_search_filters_discounted_by_store_offer(): void
    {
        $discounted = $this->networkProduct('Виски со скидкой');
        $plain = $this->networkProduct('Виски без скидки');

        $this->offer($discounted, $this->storeA, 500.0, priceOld: 1000.0);
        $this->offer($plain, $this->storeA, 500.0);
        // В магазине B скидки на этот товар нет — фильтр обязан её не увидеть.
        $this->offer($discounted, $this->storeB, 500.0);

        $query = urlencode('Виски');

        $inA = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query={$query}&onlyDiscounted=1");
        $inA->assertOk();
        $this->assertSame([$discounted->id], collect($inA->json('data'))->pluck('id')->all());
        $this->assertSame(1, $inA->json('total'));
        $this->assertEquals(1000.0, (float) $inA->json('data.0.priceOld'));

        $inB = $this->getJson("/api/v1/search/stores/{$this->storeB->id}/products?query={$query}&onlyDiscounted=1");
        $inB->assertOk();
        $this->assertSame([], $inB->json('data'), 'скидка чужого магазина в выдачу не попадает');
        $this->assertSame(0, $inB->json('total'));
    }

    /**
     * Дозапрос страниц: одной пачки хитов Meili не хватает, когда фильтрация
     * по офферам отсеивает почти всё. Без дозапроса выдача была бы пустой.
     */
    public function test_store_scoped_search_refetches_hits_until_page_is_filled(): void
    {
        config(['meilisearch.search.max_hits' => 10]);

        // 25 карточек сети без офферов магазина A идут в индексе первыми.
        for ($i = 1; $i <= 25; $i++) {
            $this->networkProduct("Виски пустой {$i}");
        }

        $expected = [];
        for ($i = 1; $i <= 3; $i++) {
            $product = $this->networkProduct("Виски доступный {$i}");
            $this->offer($product, $this->storeA, 100.0 * $i);
            $expected[] = $product->id;
        }

        $response = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query=".urlencode('Виски'));
        $response->assertOk();

        $this->assertSame(3, $response->json('total'));
        $this->assertEqualsCanonicalizing($expected, collect($response->json('data'))->pluck('id')->all());
    }

    /**
     * Дедуп id между дозапрошенными пачками: offset-пагинация Meili не даёт
     * консистентного снапшота, и один и тот же товар может прийти и в page0,
     * и в page1 (напр. параллельный reindex сдвинул позиции). Без guard'а
     * дубль задваивает total и ломает keyBy('id') при загрузке — data
     * оказывается короче perPage.
     */
    public function test_store_scoped_search_dedupes_hits_across_pages(): void
    {
        config(['meilisearch.search.max_hits' => 3]);

        $products = [];
        for ($i = 1; $i <= 4; $i++) {
            $product = $this->networkProduct("Виски дубль {$i}");
            $this->offer($product, $this->storeA, 100.0 * $i);
            $products[] = $product;
        }

        // page0 (offset0, limit3) отдаёт товары 1-3, page1 (offset3, limit3)
        // без дрейфа отдал бы только товар 4 — дрейф добавляет к нему id
        // последнего хита page0 (товар 3), имитируя нестабильный снапшот.
        $this->searchClient->simulateOffsetDrift = true;

        $response = $this->getJson(
            "/api/v1/search/stores/{$this->storeA->id}/products?query=".urlencode('Виски дубль').'&perPage=4&page=1'
        );
        $response->assertOk();

        $expected = collect($products)->pluck('id')->all();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(4, $response->json('total'), 'total не должен задваиваться дублем между пачками');
        $this->assertCount(4, $response->json('data'), 'дубль между пачками не должен схлопывать data через keyBy(id)');
        $this->assertEqualsCanonicalizing($expected, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'id не должен встречаться в выдаче дважды');
        $this->assertFalse($response->json('hasMore'));
    }

    // ==================== 2. Глобальный поиск ====================

    /**
     * Без адреса выдача плоская (сеть = один каталог) и показывает лучший
     * оффер сети: сначала магазины с остатком, потом минимальная цена.
     */
    public function test_global_search_is_flat_and_uses_best_network_offer(): void
    {
        $product = $this->networkProduct('Виски сетевой');
        $withoutOffer = $this->networkProduct('Виски без оффера');

        $this->offer($product, $this->storeA, 999.0, quantity: 3);
        $this->offer($product, $this->storeB, 700.0, quantity: 4);

        $response = $this->getJson('/api/v1/search/products?query='.urlencode('Виски'));
        $response->assertOk();

        $this->assertTrue(array_is_list($response->json('data')), 'в сетевом режиме data — плоский список товаров');
        $this->assertSame([$product->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(1, $response->json('total'));
        $this->assertEquals(700.0, (float) $response->json('data.0.price'));
        $this->assertFalse(
            collect($response->json('data'))->contains(fn ($i) => (int) $i['id'] === $withoutOffer->id),
            'товар без единого оффера сети в выдачу не попадает'
        );
    }

    /**
     * С активным адресом контекст — первый доступный магазин: цена его оффера,
     * товары без оффера в нём скрыты.
     */
    public function test_global_search_uses_store_of_active_address(): void
    {
        $inStoreA = $this->networkProduct('Виски сетевой');
        $inStoreB = $this->networkProduct('Виски соседний');

        $this->offer($inStoreA, $this->storeA, 999.0);
        $this->offer($inStoreB, $this->storeB, 500.0);

        $user = User::factory()->create();
        Address::factory()->active()->create([
            'user_id' => $user->id, 'latitude' => 55.7558, 'longitude' => 37.6173,
        ]);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            ['id' => 1, 'code' => $this->storeA->code, 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/search/products?query='.urlencode('Виски'));
        $response->assertOk();

        $this->assertSame([$inStoreA->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(1, $response->json('total'));
        $this->assertEquals(999.0, (float) $response->json('data.0.price'));
    }

    /**
     * onlyDiscounted в глобальной выдаче: без контекста магазина скидкой
     * считается price_old любого валидного оффера сети.
     */
    public function test_global_search_filters_discounted_by_network_offer(): void
    {
        $discounted = $this->networkProduct('Виски со скидкой');
        $plain = $this->networkProduct('Виски без скидки');

        $this->offer($discounted, $this->storeB, 500.0, priceOld: 1000.0);
        $this->offer($plain, $this->storeA, 500.0);

        $response = $this->getJson('/api/v1/search/products?query='.urlencode('Виски').'&onlyDiscounted=1');
        $response->assertOk();

        $this->assertSame([$discounted->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(1, $response->json('total'));
    }

    /**
     * Дозапрос страниц в глобальной сетевой выдаче — та же механика, что и
     * у поиска по магазину.
     */
    public function test_global_search_refetches_hits_until_page_is_filled(): void
    {
        config(['meilisearch.search.max_hits' => 10]);

        for ($i = 1; $i <= 25; $i++) {
            $this->networkProduct("Виски пустой {$i}");
        }

        $expected = [];
        for ($i = 1; $i <= 3; $i++) {
            $product = $this->networkProduct("Виски доступный {$i}");
            $this->offer($product, $this->storeA, 100.0 * $i);
            $expected[] = $product->id;
        }

        $response = $this->getJson('/api/v1/search/products?query='.urlencode('Виски'));
        $response->assertOk();

        $this->assertSame(3, $response->json('total'));
        $this->assertEqualsCanonicalizing($expected, collect($response->json('data'))->pluck('id')->all());
    }

    // ==================== 3. Подсказки ====================

    /**
     * Подсказки в сетевом режиме не отсекаются фильтром store_id/price:
     * глобальный документ принадлежит якорному магазину и имеет нулевую цену.
     */
    public function test_suggestions_use_network_offers(): void
    {
        $withOffer = $this->networkProduct('Виски сетевой');
        $this->networkProduct('Виски без оффера');

        $this->offer($withOffer, $this->storeA, 999.0);

        $response = $this->getJson('/api/v1/search/suggestions?query='.urlencode('Виски'));
        $response->assertOk();

        $this->assertSame(['Виски сетевой'], $response->json());
    }

    /**
     * Подсказки не должны деградировать до нуля, когда первую пачку хитов
     * целиком съедает фильтрация по офферам: добираем следующие пачки.
     */
    public function test_suggestions_refetch_hits_when_first_batch_has_no_offers(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->networkProduct("Виски пустой {$i}");
        }

        $expected = [];
        for ($i = 1; $i <= 5; $i++) {
            $product = $this->networkProduct("Виски доступный {$i}");
            $this->offer($product, $this->storeA, 100.0 * $i);
            $expected[] = $product->title;
        }

        $response = $this->getJson('/api/v1/search/suggestions?query='.urlencode('Виски').'&limit=10');
        $response->assertOk();

        $this->assertEqualsCanonicalizing($expected, $response->json());
    }

    // ==================== 4. Рекомендации ====================

    /**
     * Подборка для экрана поиска в сети отбирается и оценивается по офферам:
     * products.store_id у всех карточек якорный, а цена карточки нулевая.
     */
    public function test_recommendations_use_network_offers(): void
    {
        $recommended = $this->networkProduct('Виски рекомендованный');
        $recommended->forceFill(['on_search_page' => true])->save();
        $withoutOffer = $this->networkProduct('Виски без оффера');
        $withoutOffer->forceFill(['on_search_page' => true])->save();

        $this->offer($recommended, $this->storeA, 640.0);

        $response = $this->getJson('/api/v1/search/recommendations');
        $response->assertOk();

        $this->assertSame([$recommended->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertEquals(640.0, (float) $response->json('data.0.price'));
    }

    /**
     * С активным адресом подборка ограничена магазином контекста.
     */
    public function test_recommendations_respect_store_of_active_address(): void
    {
        $inStoreA = $this->networkProduct('Виски рекомендованный');
        $inStoreB = $this->networkProduct('Виски соседний');

        $this->offer($inStoreA, $this->storeA, 640.0);
        $this->offer($inStoreB, $this->storeB, 300.0);

        $user = User::factory()->create();
        Address::factory()->active()->create([
            'user_id' => $user->id, 'latitude' => 55.7558, 'longitude' => 37.6173,
        ]);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            ['id' => 1, 'code' => $this->storeA->code, 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/search/recommendations');
        $response->assertOk();

        $this->assertSame([$inStoreA->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertEquals(640.0, (float) $response->json('data.0.price'));
    }

    // ==================== 5. Легаси ====================

    /**
     * При выключенном модуле поиск снова работает по документу Meili
     * (store_id/price/in_stock), офферы store_products игнорируются.
     */
    public function test_legacy_mode_keeps_document_based_search(): void
    {
        $this->setNetworkCatalog(false);

        $ownProduct = Product::factory()->create([
            'store_id' => $this->storeA->id,
            'is_active' => true,
            'title' => 'Виски магазинный',
            'price' => 250.0,
            'quantity' => 5,
            'quantity_step' => 1,
        ]);
        $this->searchService->indexProduct($ownProduct);

        // Карточка якорного магазина с оффером в A: в легаси-режиме её нет
        // в выдаче магазина A — она принадлежит другому store_id и без цены.
        $networkCard = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'is_active' => true,
            'title' => 'Виски сетевой',
            'price' => 0,
            'quantity' => 0,
            'quantity_step' => 1,
        ]);
        $this->searchService->indexProduct($networkCard);
        $this->offer($networkCard, $this->storeA, 999.0);

        $response = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/products?query=".urlencode('Виски'));
        $response->assertOk();

        $this->assertSame([$ownProduct->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertEquals(250.0, (float) $response->json('data.0.price'));

        // Глобальный поиск остаётся сгруппированным по магазинам.
        $grouped = $this->getJson('/api/v1/search/products?query='.urlencode('Виски'));
        $grouped->assertOk();
        $this->assertArrayHasKey('store', $grouped->json('data.0'));
        $this->assertArrayHasKey('totalInStore', $grouped->json('data.0'));
    }

    // ==================== Фильтры поиска в магазине (MID-304) ====================

    private function manufacturer(Product $product, int $valueId, string $code, string $name): void
    {
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

    public function test_store_scoped_search_filters_by_offer_price_and_attribute(): void
    {
        $cheap = $this->networkProduct('Wine red');
        $middle = $this->networkProduct('Wine white');
        $expensive = $this->networkProduct('Wine rose');

        $this->offer($cheap, $this->storeA, 100.0);
        $this->offer($middle, $this->storeA, 500.0);
        $this->offer($expensive, $this->storeA, 900.0);
        // В магазине B средний товар дешёвый — на выдачу A влиять не должен.
        $this->offer($middle, $this->storeB, 50.0);

        $this->manufacturer($cheap, 10, 'crimea', 'Крым');
        $this->manufacturer($middle, 10, 'crimea', 'Крым');
        $this->manufacturer($expensive, 11, 'kuban', 'Кубань');

        $endpoint = "/api/v1/search/stores/{$this->storeA->id}/products?query=Wine";

        $byPrice = $this->getJson($endpoint.'&priceMin=300&priceMax=1000')->assertOk();
        $this->assertEqualsCanonicalizing([$middle->id, $expensive->id], collect($byPrice->json('data'))->pluck('id')->all());

        $combined = $this->getJson($endpoint.'&priceMin=300&filter[manufacturer]=crimea')->assertOk();
        $this->assertSame([$middle->id], collect($combined->json('data'))->pluck('id')->all());
        $this->assertSame(1, $combined->json('total'));

        $count = $this->getJson($endpoint.'&filter[manufacturer]=crimea&countOnly=1')->assertOk();
        $this->assertSame(['total' => 2], $count->json());
    }

    public function test_store_scoped_search_filters_endpoint_uses_offer_prices(): void
    {
        $cheap = $this->networkProduct('Beer lager');
        $expensive = $this->networkProduct('Beer stout');
        $foreign = $this->networkProduct('Beer ale');

        $this->offer($cheap, $this->storeA, 79.9);
        $this->offer($expensive, $this->storeA, 250.1);
        $this->offer($foreign, $this->storeB, 1000.0);

        $this->manufacturer($cheap, 10, 'baltika', 'Балтика');
        $this->manufacturer($expensive, 11, 'guinness', 'Guinness');
        $this->manufacturer($foreign, 12, 'hoegaarden', 'Hoegaarden');

        $response = $this->getJson("/api/v1/search/stores/{$this->storeA->id}/filters?query=Beer&priceMin=100")->assertOk();
        $sections = collect($response->json('data'));

        $price = $sections->firstWhere('code', 'price');
        $this->assertSame(79, $price['min']);
        $this->assertSame(251, $price['max']);

        $counts = collect($sections->firstWhere('code', 'manufacturer')['values'])->pluck('count', 'code')->all();
        // Товар без оффера в магазине A в значения не попадает.
        $this->assertSame(['guinness' => 1, 'baltika' => 0], $counts);
    }
}
