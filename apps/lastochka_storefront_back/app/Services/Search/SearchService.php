<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Address;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogFilterCriteria;
use App\Services\Catalog\CatalogFilterFacets;
use App\Services\Catalog\CatalogFilterQuery;
use App\Services\Catalog\StoreOfferContext;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Settings\ModuleConfigService;
use App\Services\Store\AvailableStoreService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class SearchService
{
    /** Кеш списка id магазинов с ignore_stock (см. {@see ignoreStockStoreIds()}). */
    public const IGNORE_STOCK_STORES_CACHE_KEY = 'search:ignore_stock_store_ids';

    /**
     * Сколько пачек хитов Meili максимум дозапрашивает товарная выдача
     * в сетевом режиме ({@see collectValidHits()}).
     */
    private const NETWORK_HIT_PAGES = 5;

    /** То же для подсказок — они дешевле и им хватает меньшего охвата. */
    private const NETWORK_SUGGESTION_PAGES = 3;

    /**
     * Скрыть 18+ из выдачи. Именно NOT ... = true, а не adult_only = false:
     * Meili под `= false` не пропускает документы, у которых поля нет вовсе
     * (проиндексированы до появления adult_only и не переписаны reindex'ом),
     * а под NOT — пропускает. Иначе после деплоя без search:reindex товар
     * виден в каталоге, но пропадает из поиска.
     */
    private const ADULT_HIDDEN_FILTER = 'NOT adult_only = true';

    /**
     * Сортировки выдачи магазина, которые пересортировывают хиты
     * ({@see sortProductIds()}). relevance и нераспознанные значения —
     * порядок Meilisearch как есть.
     */
    private const STORE_SORTS = ['popular', 'price_asc', 'price_desc', 'discount', 'newest', 'name'];

    public function __construct(
        private readonly SearchClientInterface $client,
        private readonly AvailableStoreService $availableStoreService,
        private readonly ModuleConfigService $moduleConfig,
        private readonly CatalogFilterQuery $filterQuery,
        private readonly CatalogFilterFacets $filterFacets,
    ) {}

    /**
     * Показывать ли 18+ товары в выдаче. Fail-safe default — скрывать;
     * контроллер обязан явно передать решение {@see AdultContentPolicy}
     * через {@see withAdultVisibility()}.
     */
    private bool $showAdult = false;

    /**
     * Задать видимость 18+ товаров для текущего запроса. Учитывается во всех
     * Meili-фильтрах ({@see visibilityFilters()}), кеш-ключах и БД-выборке
     * рекомендаций.
     */
    public function withAdultVisibility(bool $showAdult): static
    {
        $this->showAdult = $showAdult;

        return $this;
    }

    /**
     * Сбросить кеш списка магазинов с ignore_stock.
     * Дёргается при изменении магазина (StoreEventTransformer), чтобы
     * переключение «не учитывать остатки» в админке применялось сразу.
     */
    public static function forgetIgnoreStockStoresCache(): void
    {
        Cache::forget(self::IGNORE_STOCK_STORES_CACHE_KEY);
    }

    /**
     * Базовые Meilisearch-фильтры видимости товара.
     * Синхронны со {@see Product::scopePurchasable()} — то, что попадает
     * в листинги, должно совпадать с тем, что доступно на карточке.
     *
     * $withStock = false — без условия остатка: так ищет
     * {@see unavailableInStore()}, которому нужны как раз отсечённые по остатку товары.
     *
     * @return list<string>
     */
    private function visibilityFilters(bool $withStock = true): array
    {
        // Сетевой каталог: у документа поля price/quantity/in_stock взяты с
        // якорной карточки и всегда нулевые (цена и остаток живут в
        // store_products), поэтому фильтровать по ним нельзя — выдача была бы
        // пустой. Доступность считает БД по офферам магазина.
        if ($this->moduleConfig->isNetworkCatalogEnabled()) {
            return $this->networkVisibilityFilters();
        }

        $filters = ['is_active = true', 'price > 0'];

        if (! $this->showAdult) {
            $filters[] = self::ADULT_HIDDEN_FILTER;
        }

        if (! $this->moduleConfig->isConfigurableProductsEnabled()) {
            $filters[] = 'is_configurable = false';
        }

        if ($withStock && ! $this->moduleConfig->isPreordersEnabled()) {
            $stockExpr = 'in_stock = true OR is_configurable = true';

            // Магазины с «не учитывать остатки» ({@see Store::$ignore_stock})
            // отдают товары независимо от quantity. in_stock в документе
            // считается по реальному остатку, поэтому добавляем магазины
            // ignore_stock как явное исключение во фильтр (без переиндексации).
            $ignoreStockStoreIds = $this->ignoreStockStoreIds();
            if (! empty($ignoreStockStoreIds)) {
                $stockExpr .= ' OR store_id IN [' . implode(', ', $ignoreStockStoreIds) . ']';
            }

            $filters[] = '(' . $stockExpr . ')';
        }

        return $filters;
    }

    /**
     * Meilisearch-фильтры видимости в сетевом режиме: остаются только
     * признаки самой карточки. Цена, остаток и принадлежность магазину
     * (store_id документа — якорный магазин, один на всю сеть) проверяются
     * в БД по store_products.
     *
     * @return list<string>
     */
    private function networkVisibilityFilters(): array
    {
        $filters = ['is_active = true'];

        if (! $this->showAdult) {
            $filters[] = self::ADULT_HIDDEN_FILTER;
        }

        if (! $this->moduleConfig->isConfigurableProductsEnabled()) {
            $filters[] = 'is_configurable = false';
        }

        return $filters;
    }

    /**
     * Id магазинов в режиме «не учитывать остатки» (shops.ignore_stock).
     * Кешируем коротко — список меняется редко, а дёргается на каждый поиск.
     *
     * @return array<int>
     */
    private function ignoreStockStoreIds(): array
    {
        return Cache::remember(
            self::IGNORE_STOCK_STORES_CACHE_KEY,
            (int) config('meilisearch.cache_ttl', 30),
            static fn (): array => Store::query()->where('ignore_stock', true)->pluck('id')->all(),
        );
    }

    /**
     * Поиск товаров с группировкой по магазинам.
     *
     * Дополнительно возвращает name-match категории (METRO-семантика,
     * см. {@see searchCategories()}) — ключ categories.
     *
     * @return array{
     *     stores: Collection<int, array{store: Store, products: Collection<int, Product>, totalInStore: int}>,
     *     total: int,
     *     hasMore: bool,
     *     categories: EloquentCollection<int, Category>,
     * }
     */
    public function search(
        string $query,
        int $page = 1,
        int $perPage = 10,
        int $productsPerStore = 10,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?Address $address = null,
        ?User $user = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        int $categoriesLimit = 5
    ): array {
        $isB2b = $user?->is_b2b ?? false;
        $cacheKey = $this->getCacheKey($query, $page, $perPage, $productsPerStore, $isB2b, $onlyDiscounted, $sugarFree, $brandId, $categoryId, $categoriesLimit);
        $cacheTtl = config('meilisearch.cache_ttl', 30);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($query, $page, $perPage, $productsPerStore, $onlyDiscounted, $sugarFree, $address, $user, $brandId, $categoryId, $categoriesLimit) {
            return $this->executeSearch($query, $page, $perPage, $productsPerStore, $onlyDiscounted, $sugarFree, $address, $user, $brandId, $categoryId, $categoriesLimit);
        });
    }

    /**
     * Плоский поиск товаров для marketplace режима (скрытый маркетплейс).
     *
     * Клиент не должен видеть магазины-партнёры, поэтому результат — единый
     * список товаров по релевантности Meilisearch, без группировки по store.
     * Пагинация — по товарам. Фильтрация по доступным магазинам (адрес, B2B)
     * идентична стандартному {@see search()}.
     *
     * @return array{
     *     products: Collection<int, Product>,
     *     total: int,
     *     hasMore: bool,
     *     categories: EloquentCollection<int, \App\Models\Category>,
     * }
     */
    public function searchMarketplace(
        string $query,
        int $page = 1,
        int $perPage = 10,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?Address $address = null,
        ?User $user = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        int $categoriesLimit = 5
    ): array {
        $isB2b = $user?->is_b2b ?? false;
        $cacheKey = $this->getMarketplaceCacheKey($query, $page, $perPage, $isB2b, $onlyDiscounted, $sugarFree, $brandId, $categoryId, $categoriesLimit, $address?->id);
        $cacheTtl = config('meilisearch.cache_ttl', 30);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($query, $page, $perPage, $onlyDiscounted, $sugarFree, $address, $user, $brandId, $categoryId, $categoriesLimit) {
            return $this->executeMarketplaceSearch($query, $page, $perPage, $onlyDiscounted, $sugarFree, $address, $user, $brandId, $categoryId, $categoriesLimit);
        });
    }

    /**
     * Плоский поиск товаров в сетевом режиме (network_catalog).
     *
     * Сеть — единый каталог: карточка глобальна, магазины отличаются только
     * офферами (store_products), поэтому группировать выдачу по магазинам
     * нечем и незачем — результат плоский, как в marketplace-режиме.
     * Цена и остаток берутся из оффера магазина, переданного в $storeId, иначе
     * из магазина активного адреса, а без того и другого — из лучшего оффера
     * сети ({@see StoreOfferContext::eagerLoad()}).
     *
     * @return array{
     *     products: Collection<int, Product>,
     *     total: int,
     *     hasMore: bool,
     *     categories: EloquentCollection<int, Category>,
     * }
     */
    public function searchNetwork(
        string $query,
        int $page = 1,
        int $perPage = 10,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?Address $address = null,
        ?User $user = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        int $categoriesLimit = 5,
        ?int $storeId = null
    ): array {
        $isB2b = $user?->is_b2b ?? false;
        // storeId обязателен в ключе: выдача и цены считаются по конкретному
        // магазину, без него ответ одного магазина отдавался бы всем остальным.
        $cacheKey = $this->getNetworkCacheKey($query, $page, $perPage, $isB2b, $onlyDiscounted, $sugarFree, $brandId, $categoryId, $categoriesLimit, $address?->id, $storeId);
        $cacheTtl = config('meilisearch.cache_ttl', 30);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($query, $page, $perPage, $onlyDiscounted, $sugarFree, $address, $user, $brandId, $categoryId, $categoriesLimit, $storeId) {
            return $this->executeNetworkSearch($query, $page, $perPage, $onlyDiscounted, $sugarFree, $address, $user, $brandId, $categoryId, $categoriesLimit, $storeId);
        });
    }

    /**
     * Поиск товаров в конкретном магазине.
     *
     * Дополнительно возвращает ключ categories — фасеты по фактической выдаче
     * (категории, в которых лежат найденные товары, с количеством). В отличие
     * от {@see search()}, где категории ищутся по названию, здесь расхождение
     * между движками невозможно по построению: чип не может привести к пустому
     * экрану, потому что появляется только из товаров этой же выдачи.
     * См. {@see buildCategoryFacets()}.
     *
     * $criteria — выбор на экране «Фильтр» (цена, select-атрибуты, флаги):
     * проверяется в БД поверх хитов Meili тем же кодом, что и листинг
     * категории ({@see CatalogFilterQuery::applyCriteria()}).
     *
     * @return array{
     *     products: Collection<int, Product>,
     *     total: int,
     *     hasMore: bool,
     *     categories: list<array{id: int, name: string, count: int}>,
     * }
     */
    public function searchInStore(
        string $query,
        int $storeId,
        int $page = 1,
        int $perPage = 20,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        ?string $sort = null,
        ?CatalogFilterCriteria $criteria = null,
    ): array {
        try {
            $offset = ($page - 1) * $perPage;
            $network = $this->moduleConfig->isNetworkCatalogEnabled();
            $sort = in_array($sort, self::STORE_SORTS, true) ? $sort : null;

            // Фильтры без categoryId — база и для товарной выдачи, и для фасетов.
            $baseFilters = $this->storeSearchFilters($storeId, $onlyDiscounted, $sugarFree, $brandId);

            $filters = $categoryId !== null
                ? array_merge($baseFilters, ["category_ids = {$categoryId}"])
                : $baseFilters;

            // Сетевой режим: при запрошенной сортировке собираем максимум
            // доступного — сортировать надо всю выдачу, а не только первые
            // набранные хиты.
            $collected = $this->collectStoreHits(
                $query,
                $storeId,
                $filters,
                $onlyDiscounted === true,
                $criteria,
                $sort !== null ? PHP_INT_MAX : $offset + $perPage + 1,
            );

            $validOrdered = $collected['validOrdered'];

            if ($sort !== null) {
                $validOrdered = $this->sortProductIds($validOrdered, $sort, $network ? $storeId : null);
            }

            $total = count($validOrdered);
            $pageIds = array_slice($validOrdered, $offset, $perPage);
            $products = $this->loadProducts($pageIds, $network ? $storeId : null);

            return [
                'products' => $products,
                'total' => $total,
                'hasMore' => ($offset + $perPage) < $total,
                'categories' => $this->categoryFacets($query, $baseFilters, $storeId, $categoryId, $collected['hits'], $collected['validIds'], $onlyDiscounted === true, $criteria),
            ];
        } catch (\Exception $e) {
            Log::error('Search in store failed', [
                'query' => $query,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            // Graceful degradation
            return [
                'products' => collect(),
                'total' => 0,
                'hasMore' => false,
                'categories' => [],
            ];
        }
    }

    /**
     * Количество товаров поиска в магазине — для кнопки «Показать N товаров».
     * Те же параметры и тот же отбор, что у {@see searchInStore()}, но без
     * загрузки карточек и чипов категорий.
     */
    public function countInStore(
        string $query,
        int $storeId,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        ?CatalogFilterCriteria $criteria = null,
    ): int {
        try {
            return count($this->collectStoreHits(
                $query,
                $storeId,
                $this->storeSearchFilters($storeId, $onlyDiscounted, $sugarFree, $brandId, $categoryId),
                $onlyDiscounted === true,
                $criteria,
                PHP_INT_MAX,
            )['validOrdered']);
        } catch (\Exception $e) {
            Log::error('Search count in store failed', [
                'query' => $query,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Секции экрана «Фильтр» для выдачи поиска в магазине.
     *
     * Выдача без выбора пользователя (запрос + categoryId, brandId,
     * onlyDiscounted, sugarFree, видимость 18+) задаёт состав секций и значений,
     * а $criteria — счётчики доступности ({@see CatalogFilterFacets}): формат
     * тот же, что у фильтров категории.
     *
     * @return list<array<string, mixed>>
     */
    public function filtersInStore(
        string $query,
        int $storeId,
        CatalogFilterCriteria $criteria,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?int $brandId = null,
        ?int $categoryId = null,
    ): array {
        try {
            $ids = $this->collectStoreHits(
                $query,
                $storeId,
                $this->storeSearchFilters($storeId, $onlyDiscounted, $sugarFree, $brandId, $categoryId),
                $onlyDiscounted === true,
                null,
                PHP_INT_MAX,
            )['validOrdered'];

            $networkStoreId = $this->moduleConfig->isNetworkCatalogEnabled() ? $storeId : null;

            return $this->filterFacets->sections(
                fn () => Product::query()
                    ->whereIn('products.id', $ids)
                    ->when($networkStoreId !== null, fn ($q) => $q->joinStoreOffer($networkStoreId)),
                $criteria,
                $networkStoreId,
            );
        } catch (\Exception $e) {
            Log::error('Search filters in store failed', [
                'query' => $query,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return CatalogFilterFacets::empty();
        }
    }

    /**
     * Meili-фильтры поиска в магазине.
     *
     * Сетевой каталог: карточка глобальна (документ один на товар и
     * принадлежит якорному магазину), поэтому store_id в Meili не уходит —
     * выдачу магазина отбирает БД по офферам store_products.
     *
     * @return list<string>
     */
    private function storeSearchFilters(int $storeId, ?bool $onlyDiscounted, ?bool $sugarFree, ?int $brandId, ?int $categoryId = null): array
    {
        return array_merge(
            $this->moduleConfig->isNetworkCatalogEnabled() ? [] : ["store_id = {$storeId}"],
            $this->visibilityFilters(),
            $this->buildFilters($onlyDiscounted, $sugarFree, $brandId, $categoryId),
        );
    }

    /**
     * Хиты поиска в магазине и их отбор по БД в порядке релевантности.
     *
     * Тянем все хиты, чтобы счётчик считался по реальному остатку из БД,
     * а не по (возможно устаревшему) Meili-индексу. Иначе total включает
     * товары с устаревшим in_stock=true, которых нет в выдаче.
     *
     * Сетевой режим: хиты глобальные, и большую их часть может отсеять
     * фильтрация по офферам магазина — одной пачки на страницу не хватает.
     * Дозапрашиваем следующие пачки с offset ({@see collectValidHits()}),
     * пока не наберём $needed валидных id.
     *
     * @param  list<string>  $filters
     * @return array{hits: list<array<string, mixed>>, validIds: array<int, int>, validOrdered: list<int>}
     */
    private function collectStoreHits(
        string $query,
        int $storeId,
        array $filters,
        bool $onlyDiscounted,
        ?CatalogFilterCriteria $criteria,
        int $needed,
    ): array {
        $maxHits = (int) config('meilisearch.search.max_hits', 1000);

        if ($this->moduleConfig->isNetworkCatalogEnabled()) {
            return $this->collectValidHits(
                $query,
                implode(' AND ', $filters),
                $maxHits,
                $needed,
                self::NETWORK_HIT_PAGES,
                fn (array $ids): array => $this->narrowByCriteria(
                    $this->storeValidProductIds($ids, $storeId, $onlyDiscounted),
                    $storeId,
                    $criteria,
                ),
            );
        }

        $hits = $this->client->search($query, [
            'filter' => implode(' AND ', $filters),
            'limit' => $maxHits,
        ])['hits'];

        $allIds = array_column($hits, 'id');
        $validIds = $this->narrowByCriteria($this->validProductIds($allIds), $storeId, $criteria);

        return [
            'hits' => $hits,
            'validIds' => $validIds,
            'validOrdered' => array_values(array_filter($allIds, fn ($id) => isset($validIds[$id]))),
        ];
    }

    /**
     * Товары по запросу, которые продаются в магазине, но отсечены выдачей
     * из-за отсутствия остатка: «искали, но нет в наличии» для лога поиска
     * ({@see SearchQueryLogger}).
     *
     * Выдача {@see searchInStore()} таких товаров не видит вовсе: Meili
     * отбрасывает их фильтром in_stock. Поэтому здесь отдельный запрос с теми
     * же фильтрами видимости, но без условия остатка, а остаток проверяет БД.
     * Фильтры экрана (категория, бренд, цена) не применяются: считаем спрос
     * на товар, а не на выборку. С модулем «Под заказ» товары без остатка
     * остаются в выдаче, отсекать по остатку нечего.
     *
     * @return list<int> id товаров в порядке релевантности, не больше $limit
     */
    public function unavailableInStore(string $query, int $storeId, int $limit = 50): array
    {
        if ($this->moduleConfig->isPreordersEnabled()) {
            return [];
        }

        $network = $this->moduleConfig->isNetworkCatalogEnabled();

        $filters = $network
            ? $this->networkVisibilityFilters()
            : array_merge(["store_id = {$storeId}"], $this->visibilityFilters(withStock: false));

        $hits = $this->client->search($query, [
            'filter' => implode(' AND ', $filters),
            'limit' => (int) config('meilisearch.search.max_hits', 1000),
            'attributesToRetrieve' => ['id'],
        ])['hits'];

        $ids = array_map('intval', array_column($hits, 'id'));

        if ($ids === []) {
            return [];
        }

        $available = $network
            ? $this->storeValidProductIds($ids, $storeId)
            : $this->validProductIds($ids);

        // Карточка продаётся в магазине (активна, с ценой), но остатка нет.
        $listed = Product::query()
            ->whereIn('products.id', $ids)
            ->active()
            ->when(
                $network,
                fn ($q) => $q->whereHas('storeProducts', fn ($sq) => $sq
                    ->where('store_products.store_id', $storeId)
                    ->where('store_products.is_active', true)
                    ->where('store_products.price', '>', 0)),
                fn ($q) => $q->hasPrice(),
            )
            ->pluck('products.id')
            ->flip()
            ->all();

        $unavailable = [];

        foreach ($ids as $id) {
            if (isset($listed[$id]) && ! isset($available[$id])) {
                $unavailable[] = $id;

                if (count($unavailable) >= $limit) {
                    break;
                }
            }
        }

        return $unavailable;
    }

    /**
     * Оставить из валидных id только подходящие под выбор на экране «Фильтр».
     * В сетевом режиме цена сравнивается по офферу магазина.
     *
     * @param  array<int, int>  $validIds  карта [id => 1]
     * @return array<int, int> карта [id => 1]
     */
    private function narrowByCriteria(array $validIds, int $storeId, ?CatalogFilterCriteria $criteria): array
    {
        if ($criteria === null || $criteria->isEmpty() || $validIds === []) {
            return $validIds;
        }

        $networkStoreId = $this->moduleConfig->isNetworkCatalogEnabled() ? $storeId : null;

        $query = Product::query()
            ->whereIn('products.id', array_keys($validIds))
            ->when($networkStoreId !== null, fn ($q) => $q->joinStoreOffer($networkStoreId));

        return $this->filterQuery
            ->applyCriteria($query, $criteria, $networkStoreId)
            ->pluck('products.id')
            ->flip()
            ->all();
    }

    /**
     * Фасеты категорий для поиска в магазине.
     *
     * Секция считается так, будто categoryId не передавали: иначе после первого
     * же тапа по чипу лента чипов схлопывается в один элемент и переключиться
     * на соседнюю категорию нельзя, только снять фильтр. Остальные фильтры
     * (query, brandId, onlyDiscounted, sugarFree, выбор на экране «Фильтр»)
     * учитываются.
     *
     * Когда categoryId не передан (штатный случай), переиспользуются хиты
     * товарной выдачи и лишних запросов нет. Когда передан — делается второй
     * лёгкий запрос в Meili, а товарная выдача не затрагивается вообще.
     *
     * Собственный try: сбой сборки чипов (БД) не должен ронять товарную выдачу.
     *
     * @param  list<string>  $baseFilters  фильтры без categoryId
     * @param  array<array<string, mixed>>  $hits  хиты товарной выдачи
     * @param  array<int, int>  $validIds  карта валидных id этих хитов
     * @return list<array{id: int, name: string, count: int}>
     */
    private function categoryFacets(
        string $query,
        array $baseFilters,
        int $storeId,
        ?int $categoryId,
        array $hits,
        array $validIds,
        bool $onlyDiscounted = false,
        ?CatalogFilterCriteria $criteria = null,
    ): array {
        try {
            if ($categoryId !== null) {
                $hits = $this->client->search($query, [
                    'filter' => implode(' AND ', $baseFilters),
                    'limit' => (int) config('meilisearch.search.max_hits', 1000),
                    'attributesToRetrieve' => ['id', 'category_ids'],
                ])['hits'];

                $validIds = $this->narrowByCriteria(
                    $this->visibleProductIds(array_column($hits, 'id'), $storeId, $onlyDiscounted),
                    $storeId,
                    $criteria,
                );
            }

            return $this->buildCategoryFacets($hits, $validIds, $storeId);
        } catch (\Exception $e) {
            Log::error('Search category facets failed', [
                'query' => $query,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Просуммировать category_ids хитов и догрузить названия категорий.
     *
     * Учитываются только валидные хиты — товар, которого по факту нет в наличии,
     * не должен тянуть свою категорию в чипы. Категория попадает в результат
     * только если хотя бы один найденный товар привязан к ней напрямую, а
     * categoryId транслируется в Meili-фильтр `category_ids = N`, который матчит
     * ровно прямые привязки. Поэтому фильтр «только листовые категории» не нужен
     * и был бы ошибкой: нелистовые категории тоже имеют прямые привязки товаров,
     * и чипы по ним рабочие.
     *
     * @param  array<array<string, mixed>>  $hits
     * @param  array<int, int>  $validIds
     * @return list<array{id: int, name: string, count: int}>
     */
    private function buildCategoryFacets(array $hits, array $validIds, int $storeId): array
    {
        $counts = [];

        foreach ($hits as $hit) {
            if (! isset($hit['id'], $validIds[$hit['id']])) {
                continue;
            }

            foreach ($hit['category_ids'] ?? [] as $id) {
                $id = (int) $id;
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        if (empty($counts)) {
            return [];
        }

        // Категория, отключённая в этом магазине, чипом не становится.
        // В сетевом режиме дерево общее — forStore() схлопывается в no-op.
        $categories = Category::query()
            ->whereIn('id', array_keys($counts))
            ->active()
            ->forStore($storeId)
            ->get(['id', 'name']);

        $facets = [];

        foreach ($categories as $category) {
            $facets[] = [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'count' => $counts[(int) $category->id],
            ];
        }

        usort($facets, static fn (array $a, array $b): int => $b['count'] <=> $a['count']
            ?: mb_strtolower($a['name']) <=> mb_strtolower($b['name']));

        return array_slice($facets, 0, (int) config('meilisearch.search.store_categories_limit', 30));
    }

    /**
     * Получить подсказки для автодополнения.
     *
     * При structured=false возвращает плоский string[] (backwards compatible).
     * При structured=true возвращает секции: categories, brands, products (string[]).
     * Секция categories: сначала name-match по названию ({@see searchCategories()},
     * METRO-семантика), затем добивка категориями найденных товаров, дедуп по id,
     * суммарно не более limit.
     *
     * Если передан активный адрес — фильтрует магазины через AvailableStoreService
     * (только те, что доставляют по координатам), чтобы не показывать подсказки
     * товаров из недоступных магазинов. Это выравнивает поведение с /search/products.
     *
     * Явный $storeId (магазин, выбранный на фронте) приоритетнее адреса: товарный
     * поиск на фронте идёт по выбранному магазину, и если сохранённый адрес его
     * не покрывает, категории по адресу пришли бы пустыми при непустых товарах.
     *
     * @return array<string>|array{categories: array, brands: array, products: array<string>}
     */
    public function getSuggestions(
        string $query,
        int $limit = 10,
        ?User $user = null,
        bool $structured = false,
        ?Address $address = null,
        ?int $storeId = null
    ): array {
        try {
            $maxLimit = config('meilisearch.search.suggestions_limit', 20);
            $limit = min($limit, $maxLimit);

            $allowedStoreIds = $this->resolveAllowedStoreIds($address, $user, $storeId);
            $network = $this->moduleConfig->isNetworkCatalogEnabled();

            $storeFilter = ! empty($allowedStoreIds)
                ? 'store_id IN [' . implode(',', $allowedStoreIds) . ']'
                : 'store_id = 0';

            // Сетевой каталог: store_id документа — якорный магазин, поэтому
            // фильтр по доступным магазинам отсёк бы вообще всё. Доступность
            // проверяем по офферам store_products этих магазинов уже в БД.
            $filters = $network
                ? $this->visibilityFilters()
                : array_merge($this->visibilityFilters(), [$storeFilter]);

            $pageSize = $limit * 3; // Берём больше, т.к. могут быть дубликаты

            if ($network) {
                // Фильтрация по офферам идёт после Meili и может съесть всю
                // пачку целиком (проба ревью: 0 подсказок из 5 доступных),
                // поэтому добираем следующие пачки хитов с offset.
                $collected = $this->collectValidHits(
                    $query,
                    implode(' AND ', $filters),
                    $pageSize,
                    $limit,
                    self::NETWORK_SUGGESTION_PAGES,
                    fn (array $ids): array => $this->networkValidProductIds($ids, $allowedStoreIds),
                );

                $hits = collect($collected['hits'])
                    ->filter(fn ($hit) => isset($collected['validIds'][$hit['id']]))
                    ->values();
            } else {
                $result = $this->client->search($query, [
                    'filter' => implode(' AND ', $filters),
                    'limit' => $pageSize,
                ]);

                $hits = collect($result['hits']);
            }

            // Извлекаем уникальные названия
            $titles = $hits
                ->pluck('title')
                ->unique()
                ->take($limit)
                ->values()
                ->all();

            if (! $structured) {
                return $titles;
            }

            // Категории: сначала name-match по названию (METRO-семантика),
            // добивка — категории найденных товаров, дедуп по id, суммарно ≤ limit.
            $nameMatched = $this->searchCategories($query, $allowedStoreIds, $limit);

            $categories = $nameMatched
                ->map(fn (Category $c) => $this->suggestionCategory($c))
                ->values();

            if ($categories->count() < $limit) {
                $hitCategoryIds = $hits->pluck('category_ids')->flatten()->unique()->filter()->values()->all();
                $hitCategoryIds = array_values(array_diff($hitCategoryIds, $nameMatched->pluck('id')->all()));

                if (! empty($hitCategoryIds)) {
                    $categories = $categories->concat(
                        Category::query()
                            ->whereIn('id', $hitCategoryIds)
                            ->active()
                            ->get()
                            ->take($limit - $categories->count())
                            ->map(fn (Category $c) => $this->suggestionCategory($c))
                            ->values()
                    )->values();
                }
            }

            $categories = $categories->all();

            $brandIds = $hits->pluck('brand_id')->unique()->filter()->values()->all();

            $brands = ! empty($brandIds)
                ? Brand::query()
                    ->whereIn('id', $brandIds)
                    ->available()
                    ->get()
                    ->map(fn (Brand $b) => [
                        'id' => $b->id,
                        'title' => $b->title,
                        'logoImage' => $b->logo_path
                            ? ['url' => asset('storage/' . $b->logo_path)]
                            : null,
                    ])
                    ->values()
                    ->all()
                : [];

            return [
                'categories' => $categories,
                'brands' => $brands,
                'products' => $titles,
            ];
        } catch (\Exception $e) {
            Log::error('Search suggestions failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return $structured ? ['categories' => [], 'brands' => [], 'products' => []] : [];
        }
    }

    /**
     * Элемент секции categories подсказок. slug нужен фронту для перехода
     * на страницу каталога по категории.
     *
     * @return array{id: int, name: string, slug: string}
     */
    private function suggestionCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => (string) $category->slug,
        ];
    }

    /**
     * Поиск категорий по названию (METRO-семантика): каждое слово запроса
     * должно входить в название. Матчинг в PHP через mb_stripos, а не SQL
     * LIKE — на SQLite (тесты) LIKE/LOWER регистронезависимы только для
     * ASCII, а категорий мало и полная выборка кандидатов дешёвая.
     *
     * @param  array<int>  $allowedStoreIds
     * @return EloquentCollection<int, Category>
     */
    private function searchCategories(string $query, array $allowedStoreIds, int $limit): EloquentCollection
    {
        if ($limit <= 0 || empty($allowedStoreIds)) {
            return new EloquentCollection();
        }

        $query = trim($query);
        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return new EloquentCollection();
        }

        try {
            $candidates = Category::query()
                ->active()
                ->forStores($allowedStoreIds)
                ->get();

            $matched = $candidates
                ->filter(function (Category $category) use ($words): bool {
                    foreach ($words as $word) {
                        if (mb_stripos($category->name, $word) === false) {
                            return false;
                        }
                    }

                    return true;
                })
                ->sort(function (Category $a, Category $b) use ($query): int {
                    $aPrefix = mb_stripos($a->name, $query) === 0 ? 0 : 1;
                    $bPrefix = mb_stripos($b->name, $query) === 0 ? 0 : 1;

                    return $aPrefix <=> $bPrefix
                        ?: mb_strtolower($a->name) <=> mb_strtolower($b->name);
                })
                ->take($limit)
                ->values();

            // parent — после отбора top-N, чтобы не грузить родителей всей выборки
            $matched->load('parent');

            return $matched;
        } catch (\Exception $e) {
            Log::error('Search categories failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return new EloquentCollection();
        }
    }

    /**
     * Получить подборку рекомендаций для экрана поиска.
     *
     * Источник — флаг products.on_search_page. Если помеченных товаров меньше,
     * чем запрошено, подборка добивается свежими активными товарами (fallback).
     *
     * Сетевой каталог: карточка принадлежит якорному магазину, поэтому отбор
     * по products.store_id вернул бы либо всё, либо ничего, а цена карточки
     * нулевая. Доступность и цена берутся из офферов — магазина активного
     * адреса, а без адреса из лучшего оффера сети.
     *
     * @return array{
     *     products: Collection<int, Product>,
     *     total: int,
     * }
     */
    public function getRecommendations(int $limit = 20, ?Address $address = null, ?User $user = null, ?int $explicitStoreId = null): array
    {
        $limit = max(1, min($limit, 50));

        $network = $this->moduleConfig->isNetworkCatalogEnabled();

        // В сети явный storeId приоритетнее адреса и на этапе отбора магазинов:
        // иначе адрес без покрытия давал бы пустую подборку при переданном storeId.
        $allowedStoreIds = $this->resolveAllowedStoreIds($address, $user, $network ? $explicitStoreId : null);

        if (empty($allowedStoreIds)) {
            return ['products' => collect(), 'total' => 0];
        }

        // Явный storeId приоритетнее адреса — как в остальных каталожных ручках.
        $storeId = match (true) {
            ! $network => 0,
            $explicitStoreId > 0 => (int) $explicitStoreId,
            $address !== null => (int) ($allowedStoreIds[0] ?? 0),
            default => 0,
        };

        $baseQuery = fn () => Product::query()
            ->active()
            ->adultVisible($this->showAdult)
            ->excludeChildren()
            ->when(
                $network,
                fn ($q) => $storeId > 0
                    ? $q->purchasableInStoreExists($storeId)
                    : $q->inStock()->hasPrice()->whereHas('storeProducts', fn ($sq) => $sq
                        ->whereIn('store_products.store_id', $allowedStoreIds)
                        ->where('store_products.is_active', true)
                        ->where('store_products.price', '>', 0)),
                fn ($q) => $q->inStock()->hasPrice()->whereIn('store_id', $allowedStoreIds),
            );

        $eagerLoad = array_merge(['images', 'activePromotions'], StoreOfferContext::eagerLoad($storeId));

        $flagged = $baseQuery()
            ->where('on_search_page', true)
            ->orderByDesc('updated_at')
            ->with($eagerLoad)
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
            ->limit($limit)
            ->get();

        if ($flagged->count() >= $limit) {
            return ['products' => $flagged, 'total' => $flagged->count()];
        }

        $fallback = $baseQuery()
            ->when($flagged->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $flagged->pluck('id')->all()))
            ->orderByDesc('updated_at')
            ->with($eagerLoad)
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
            ->limit($limit - $flagged->count())
            ->get();

        $products = $flagged->concat($fallback)->values()->keyBy('id');

        return ['products' => $products, 'total' => $products->count()];
    }

    /**
     * Добавить или обновить товар в индексе.
     */
    public function indexProduct(Product $product): void
    {
        // Не индексируем неактивные товары
        if (! $product->is_active) {
            $this->removeProduct($product->id);

            return;
        }

        // Не индексируем children — они скрыты из поиска
        if ($product->parent_id) {
            $this->removeProduct($product->id);

            return;
        }

        try {
            $document = $this->productToDocument($product);
            $this->client->indexDocument($document);

            Log::debug('Product indexed', ['product_id' => $product->id]);
        } catch (\Exception $e) {
            Log::error('Failed to index product', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Батч-индексация: одно обращение к Meilisearch на пачку товаров
     * (батч-события 1С). Семантика повторяет indexProduct() для каждого
     * товара: активные не-children индексируются, остальные удаляются
     * из индекса.
     *
     * @param  EloquentCollection<int, Product>  $products
     */
    public function indexProducts(EloquentCollection $products): void
    {
        if ($products->isEmpty()) {
            return;
        }

        $products->loadMissing(['activePromotions', 'categories', 'store']);

        [$indexable, $removable] = $products->partition(
            static fn (Product $p) => $p->is_active && ! $p->parent_id
        );

        try {
            if ($removable->isNotEmpty()) {
                $this->client->deleteDocuments($removable->pluck('id')->all());
            }

            if ($indexable->isNotEmpty()) {
                $this->client->indexDocuments(
                    $indexable->map(fn (Product $p) => $this->productToDocument($p))->values()->all()
                );
            }

            Log::debug('Products batch indexed', [
                'indexed' => $indexable->count(),
                'removed' => $removable->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to batch index products', [
                'count' => $products->count(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Удалить товар из индекса.
     */
    public function removeProduct(int $productId): void
    {
        try {
            $this->client->deleteDocument($productId);

            Log::debug('Product removed from index', ['product_id' => $productId]);
        } catch (\Exception $e) {
            Log::error('Failed to remove product from index', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Переиндексировать все товары.
     *
     * @return \Generator<int, array{indexed: int, total: int}>
     */
    public function reindexAll(int $chunkSize = 1000, bool $fresh = false): \Generator
    {
        if ($fresh) {
            $this->client->resetIndex();
        } else {
            $this->client->updateIndexSettings();
        }

        $total = Product::query()
            ->active()
            ->whereNull('parent_id')
            ->count();

        $indexed = 0;

        Product::query()
            ->active()
            ->whereNull('parent_id')
            ->with(['activePromotions', 'categories', 'store'])
            ->chunk($chunkSize, function ($products) use (&$indexed) {
                $documents = $products->map(fn (Product $p) => $this->productToDocument($p))->all();

                try {
                    $this->client->indexDocuments($documents);
                    $indexed += count($documents);

                } catch (\Exception $e) {
                    Log::error('Failed to index chunk', [
                        'indexed' => $indexed,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        yield ['indexed' => $indexed, 'total' => $total];
    }

    /**
     * Проверить доступность Meilisearch.
     */
    public function isHealthy(): bool
    {
        return $this->client->isHealthy();
    }

    /**
     * Выполнить поиск без кеша.
     */
    private function executeSearch(
        string $query,
        int $page,
        int $perPage,
        int $productsPerStore,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?Address $address = null,
        ?User $user = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        int $categoriesLimit = 5
    ): array {
        // Категории по названию (METRO-семантика) — независимо от Meilisearch:
        // при его недоступности товарная выдача деградирует, категории остаются.
        // Собственный try: сбой resolveAllowedStoreIds (сервис магазинов/БД) не
        // должен ронять весь поиск — раньше он был под общим try-catch.
        try {
            $categories = $this->searchCategories(
                $query,
                $this->resolveAllowedStoreIds($address, $user),
                $categoriesLimit,
            );
        } catch (\Exception $e) {
            Log::error('Search categories failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $categories = new EloquentCollection();
        }

        try {
            $maxHits = config('meilisearch.search.max_hits', 1000);

            $filters = array_merge(
                $this->visibilityFilters(),
                $this->buildFilters($onlyDiscounted, $sugarFree, $brandId, $categoryId),
            );

            $result = $this->client->search($query, [
                'filter' => implode(' AND ', $filters),
                'limit' => $maxHits,
            ]);

            // Получаем доступные магазины для адреса с учётом B2B
            $availableStoreIds = $address
                ? $this->availableStoreService->getAvailableStores($address, $user)->pluck('id')->all()
                : null;

            // Группируем по store_id
            $grouped = collect($result['hits'])->groupBy('store_id');

            // Фильтруем по доступным магазинам, если адрес указан
            if ($availableStoreIds !== null) {
                $grouped = $grouped->filter(fn ($_, $storeId) => in_array((int) $storeId, $availableStoreIds));
            } else {
                // Без адреса — фильтруем по B2B типу магазина
                $allowedStoreIds = Store::query()
                    ->active()
                    ->forUserType($user)
                    ->pluck('id')
                    ->all();
                $grouped = $grouped->filter(fn ($_, $storeId) => in_array((int) $storeId, $allowedStoreIds));
            }

            // Валидируем ВСЕ хиты по реальному остатку из БД: Meili-документ
            // (in_stock) может быть устаревшим, и счётчик «N результатов» обязан
            // считаться по тому же фильтру, что и показанный список (loadProducts).
            // Иначе товар с устаревшим in_stock=true попадает в счётчик, но не в
            // выдачу → «2 результата», а карточка одна.
            $validIds = $this->validProductIds(collect($result['hits'])->pluck('id')->all());

            // Считаем totalInStore по ВАЛИДНЫМ товарам и сортируем магазины
            $storesData = $grouped->map(function ($hits, $storeId) use ($productsPerStore, $validIds) {
                $valid = $hits->filter(fn ($hit) => isset($validIds[$hit['id']]));

                return [
                    'store_id' => $storeId,
                    'product_ids' => $valid->take($productsPerStore)->pluck('id')->all(),
                    'totalInStore' => $valid->count(),
                ];
            })
                ->filter(fn ($data) => $data['totalInStore'] > 0)
                ->sortByDesc('totalInStore')
                ->values();

            // Пагинация по магазинам
            $totalStores = $storesData->count();
            $offset = ($page - 1) * $perPage;
            $pagedStores = $storesData->slice($offset, $perPage)->values();

            // Загружаем данные из БД
            $storeIds = $pagedStores->pluck('store_id')->all();
            $allProductIds = $pagedStores->pluck('product_ids')->flatten()->all();

            $stores = Store::query()
                ->whereIn('id', $storeIds)
                ->active()
                ->forUserType($user)
                ->with('schedules')
                ->get()
                ->keyBy('id');

            $products = $this->loadProducts($allProductIds);

            // Формируем результат
            $resultStores = $pagedStores
                ->filter(fn ($data) => $stores->has($data['store_id']))
                ->map(function ($data) use ($stores, $products) {
                    $storeProducts = collect($data['product_ids'])
                        ->map(fn ($id) => $products->get($id))
                        ->filter()
                        ->values();

                    return [
                        'store' => $stores->get($data['store_id']),
                        'products' => $storeProducts,
                        'totalInStore' => $data['totalInStore'],
                    ];
                })
                ->values();

            return [
                'stores' => $resultStores,
                'total' => $totalStores,
                'hasMore' => ($offset + $perPage) < $totalStores,
                'categories' => $categories,
            ];
        } catch (\Exception $e) {
            Log::error('Search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            // Graceful degradation
            return [
                'stores' => collect(),
                'total' => 0,
                'hasMore' => false,
                'categories' => $categories,
            ];
        }
    }

    /**
     * Выполнить плоский marketplace-поиск без кеша.
     *
     * Отбор хитов и валидация остатков полностью повторяют {@see executeSearch()},
     * отличие только в форме результата: единый список товаров в порядке
     * релевантности Meilisearch вместо групп по магазинам.
     */
    private function executeMarketplaceSearch(
        string $query,
        int $page,
        int $perPage,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?Address $address = null,
        ?User $user = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        int $categoriesLimit = 5
    ): array {
        // Категории по названию — независимо от Meilisearch, как в executeSearch().
        try {
            $categories = $this->searchCategories(
                $query,
                $this->resolveAllowedStoreIds($address, $user),
                $categoriesLimit,
            );
        } catch (\Exception $e) {
            Log::error('Marketplace search categories failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $categories = new EloquentCollection();
        }

        try {
            $maxHits = config('meilisearch.search.max_hits', 1000);

            $filters = array_merge(
                $this->visibilityFilters(),
                $this->buildFilters($onlyDiscounted, $sugarFree, $brandId, $categoryId),
            );

            $result = $this->client->search($query, [
                'filter' => implode(' AND ', $filters),
                'limit' => $maxHits,
            ]);

            // Доступные магазины: по адресу, иначе по типу пользователя (B2B/B2C).
            $allowedStoreIds = $address
                ? $this->availableStoreService->getAvailableStores($address, $user)->pluck('id')->all()
                : Store::query()->active()->forUserType($user)->pluck('id')->all();

            $hits = collect($result['hits'])
                ->filter(fn ($hit) => in_array((int) $hit['store_id'], $allowedStoreIds));

            // Валидация по реальному остатку из БД: счётчик и выдача считаются
            // по одному и тому же фильтру (та же логика, что в executeSearch).
            $validIds = $this->validProductIds($hits->pluck('id')->all());
            $validOrdered = $hits
                ->pluck('id')
                ->filter(fn ($id) => isset($validIds[$id]))
                ->values();

            $total = $validOrdered->count();
            $offset = ($page - 1) * $perPage;
            $pageIds = $validOrdered->slice($offset, $perPage)->values()->all();

            return [
                'products' => $this->loadProducts($pageIds),
                'total' => $total,
                'hasMore' => ($offset + $perPage) < $total,
                'categories' => $categories,
            ];
        } catch (\Exception $e) {
            Log::error('Marketplace search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            // Graceful degradation
            return [
                'products' => collect(),
                'total' => 0,
                'hasMore' => false,
                'categories' => $categories,
            ];
        }
    }

    /**
     * Выполнить плоский сетевой поиск без кеша.
     *
     * Отличия от {@see executeMarketplaceSearch()}: хиты не фильтруются по
     * store_id документа (он якорный, один на всю сеть), а доступность и цена
     * считаются по офферам store_products — магазина активного адреса, либо
     * лучшего оффера сети, если адреса нет.
     */
    private function executeNetworkSearch(
        string $query,
        int $page,
        int $perPage,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?Address $address = null,
        ?User $user = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        int $categoriesLimit = 5,
        ?int $explicitStoreId = null
    ): array {
        // Магазины, доступные пользователю: явный storeId → только он (адрес
        // не учитывается, иначе адрес без покрытия обнулял бы категории при
        // непустой выдаче по магазину), адрес → AvailableStoreService,
        // без адреса → активные магазины нужного типа (B2B/B2C).
        try {
            $allowedStoreIds = $this->resolveAllowedStoreIds($address, $user, $explicitStoreId);
        } catch (\Exception $e) {
            Log::error('Network search allowed stores failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $allowedStoreIds = [];
        }

        // Категории по названию — независимо от Meilisearch, как в executeSearch().
        try {
            $categories = $this->searchCategories($query, $allowedStoreIds, $categoriesLimit);
        } catch (\Exception $e) {
            Log::error('Network search categories failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $categories = new EloquentCollection();
        }

        try {
            $maxHits = (int) config('meilisearch.search.max_hits', 1000);

            $filters = array_merge(
                $this->visibilityFilters(),
                $this->buildFilters($onlyDiscounted, $sugarFree, $brandId, $categoryId),
            );

            // Контекст магазина: явный storeId из запроса приоритетнее адреса —
            // так же, как в остальных каталожных ручках (StoreOfferContext).
            // Затем магазин активного адреса. Без того и другого контекста нет:
            // товар виден, если продаётся хоть в одном доступном магазине,
            // а цена берётся из лучшего оффера сети.
            $storeId = $explicitStoreId > 0
                ? (int) $explicitStoreId
                : ($address !== null ? (int) ($allowedStoreIds[0] ?? 0) : 0);

            $offset = ($page - 1) * $perPage;

            // Как и в searchInStore(): фильтрация по офферам идёт после Meili,
            // поэтому страницу добираем дозапросом следующих пачек хитов.
            $collected = $this->collectValidHits(
                $query,
                implode(' AND ', $filters),
                $maxHits,
                $offset + $perPage + 1,
                self::NETWORK_HIT_PAGES,
                fn (array $ids): array => $storeId > 0
                    ? $this->storeValidProductIds($ids, $storeId, $onlyDiscounted === true)
                    : $this->networkValidProductIds($ids, $allowedStoreIds, $onlyDiscounted === true),
            );

            $validOrdered = $collected['validOrdered'];

            $total = count($validOrdered);
            $pageIds = array_slice($validOrdered, $offset, $perPage);

            return [
                'products' => $this->loadProducts($pageIds, $storeId),
                'total' => $total,
                'hasMore' => ($offset + $perPage) < $total,
                'categories' => $categories,
            ];
        } catch (\Exception $e) {
            Log::error('Network search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            // Graceful degradation
            return [
                'products' => collect(),
                'total' => 0,
                'hasMore' => false,
                'categories' => $categories,
            ];
        }
    }

    /**
     * Преобразовать Product в документ для индексации.
     *
     * Сетевой каталог (network_catalog): документ остаётся ОДИН на товар —
     * карточка глобальна и принадлежит якорному магазину, поэтому поля
     * store_id/price/price_old/quantity/in_stock/has_discount берутся с неё
     * и в сети не значимы: цена, старая цена и остаток живут в store_products.
     * Ни одно из них не используется как Meili-фильтр — видимость собирает
     * {@see networkVisibilityFilters()}, has_discount отключает
     * {@see buildFilters()}, а доступность, цену и наличие скидки считает БД
     * по офферам магазина ({@see storeValidProductIds()}).
     *
     * Массив id магазинов в документ сознательно НЕ вводится: на полной сети
     * он потребовал бы переиндексации карточки при каждом изменении
     * доступности оффера.
     *
     * @return array<string, mixed>
     */
    private function productToDocument(Product $product): array
    {
        $product->loadMissing('categories');

        $document = [
            'id' => $product->id,
            'store_id' => $product->store_id,
            'category_ids' => $product->categories->pluck('id')->all(),
            'brand_id' => $product->brand_id,
            'sku' => $product->sku,
            'title' => $product->title,
            'description' => $product->description,
            'price' => $product->price,
            'price_old' => $product->price_old,
            'quantity' => $product->quantity,
            // isStockIgnored() учитывает ОБА уровня — товарный stock_mode='ignore' И
            // магазинный stores.ignore_stock. Раньше здесь проверялся только остаток,
            // а магазинный ignore_stock подхватывался отдельным OR-условием в
            // visibilityFilters() (store_id IN [...]) — товарный ignore_stock
            // индексировался как in_stock=false и не находился полнотекстовым поиском,
            // хотя корректно проходил Product::scopePurchasable()/scopeInStock().
            'in_stock' => $product->quantity >= $product->minSaleStock() || $product->isStockIgnored(),
            // Boolean-флаги жёстко кастуем — Meilisearch фильтрует по точному типу,
            // а Eloquent на свежесозданных моделях может вернуть null для default'ных колонок.
            'is_active' => (bool) $product->is_active,
            'is_configurable' => (bool) $product->is_configurable,
            'is_culinary' => $product->is_culinary,
            'rating' => $product->rating ? (float) $product->rating : null,
            'sugar_free' => $product->sugar_free,
            'adult_only' => (bool) $product->adult_only,
            'has_discount' => $product->hasDiscount(),
            'updated_at' => $product->updated_at?->timestamp,
        ];

        // Для configurable parent — агрегированные данные из children
        if ($product->is_configurable) {
            $children = $product->children()
                ->active()
                ->select(['id', 'price', 'quantity', 'quantity_step', 'parent_id', 'is_active'])
                ->withoutEagerLoads()
                ->get();
            $childPrices = $children->map(fn ($c) => $c->price)->filter();
            $totalQuantity = $children->sum('quantity');

            $document['price'] = $childPrices->min() ?? 0;
            $document['quantity'] = $totalQuantity;
            $document['in_stock'] = $totalQuantity > 0 || $product->isStockIgnored();
        }

        return $document;
    }

    /**
     * Множество id товаров, реально доступных к показу (активен, в наличии с
     * учётом ignore_stock, имеет цену) — тот же фильтр, что в {@see loadProducts()}.
     * Используется для согласованного со списком счётчика результатов: Meili-индекс
     * может быть устаревшим, авторитет по остатку — БД.
     *
     * @param  array<int>  $ids
     * @return array<int, int> карта [id => 1] для O(1) проверки isset
     */
    private function validProductIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->active()
            ->inStock()
            ->hasPrice()
            ->pluck('id')
            ->flip()
            ->all();
    }

    /**
     * Собрать хиты Meilisearch пачками, пока не наберётся $needed валидных id.
     *
     * В сетевом режиме документ ничего не знает о магазине: доступность
     * считает БД по офферам store_products, и произвольно большая доля хитов
     * отсеивается уже после запроса. Одной пачки на страницу выдачи тогда не
     * хватает (проба ревью: total=0 вместо 3), поэтому тянем следующие пачки
     * с offset, пока не наберём нужное, не кончатся хиты или не упрёмся в
     * $maxPages.
     *
     * Offset-пагинация Meili не даёт консистентного снапшота между соседними
     * запросами: если между page0 и page1 произошёл reindex (сдвинувший
     * позиции документов), один и тот же id может прийти в обеих пачках.
     * Дедуп по $seenIds — иначе дубль задваивает total, ломает keyBy('id')
     * при загрузке товаров (data короче perPage) и задваивает счётчик чипа
     * в categoryFacets (невалидный дубль тоже не должен попасть в $hits
     * повторно).
     *
     * Упёрлись в $maxPages — total занижен: посчитан по собранным хитам, а не
     * по всей выдаче. Это принятый предел: иначе один запрос вырождается в
     * скан всего индекса сети. Порядок релевантности Meili сохраняется —
     * пачки склеиваются подряд.
     *
     * @param  callable(list<int>): array<int, int>  $validate  фильтр доступности: id → карта [id => 1]
     * @return array{hits: list<array<string, mixed>>, validIds: array<int, int>, validOrdered: list<int>}
     */
    private function collectValidHits(
        string $query,
        string $filter,
        int $pageSize,
        int $needed,
        int $maxPages,
        callable $validate
    ): array {
        $hits = [];
        $validIds = [];
        $validOrdered = [];
        // Множество уже виденных id (валидных и нет) — guard от дублей между
        // пачками при нестабильном offset-снапшоте Meili.
        $seenIds = [];
        $offset = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->client->search($query, [
                'filter' => $filter,
                'limit' => $pageSize,
                'offset' => $offset,
            ]);

            $pageHits = $result['hits'];

            if (empty($pageHits)) {
                break;
            }

            $newIds = [];

            foreach ($pageHits as $hit) {
                $id = $hit['id'];

                if (isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = 1;
                $newIds[] = $id;
                $hits[] = $hit;
            }

            if (! empty($newIds)) {
                $pageValid = $validate($newIds);

                foreach ($newIds as $id) {
                    if (isset($pageValid[$id])) {
                        $validIds[$id] = 1;
                        $validOrdered[] = $id;
                    }
                }
            }

            $offset += $pageSize;

            // Хиты кончились: пачка неполная либо offset догнал общее число.
            $exhausted = count($pageHits) < $pageSize
                || (int) ($result['estimatedTotalHits'] ?? 0) <= $offset;

            if ($exhausted || count($validOrdered) >= $needed) {
                break;
            }
        }

        return [
            'hits' => $hits,
            'validIds' => $validIds,
            'validOrdered' => $validOrdered,
        ];
    }

    /**
     * Множество id товаров, доступных к показу В МАГАЗИНЕ: в сетевом режиме —
     * по офферу store_products этого магазина, в легаси — прежняя проверка по
     * колонкам карточки ({@see validProductIds()}).
     *
     * @param  array<int>  $ids
     * @return array<int, int> карта [id => 1]
     */
    private function visibleProductIds(array $ids, int $storeId, bool $onlyDiscounted = false): array
    {
        return $this->moduleConfig->isNetworkCatalogEnabled()
            ? $this->storeValidProductIds($ids, $storeId, $onlyDiscounted)
            : $this->validProductIds($ids);
    }

    /**
     * Множество id товаров, покупаемых в конкретном магазине сети: активная
     * строка store_products с ценой и остатком (либо stock_mode=ignore /
     * stores.ignore_stock). Тот же фильтр, что у листингов магазина
     * ({@see Product::scopePurchasableInStore()}), — счётчик выдачи и сам
     * список считаются одинаково.
     *
     * $onlyDiscounted — фильтр «только со скидкой»: в сети скидку задаёт
     * price_old ОФФЕРА этого магазина либо активная акция карточки
     * ({@see \App\Http\Controllers\Api\V1\ProductController::applyDiscountedFilter()}),
     * колонка products.price_old всегда пуста.
     *
     * Вызывать только при включённом network_catalog.
     *
     * @param  array<int>  $ids
     * @return array<int, int> карта [id => 1]
     */
    private function storeValidProductIds(array $ids, int $storeId, bool $onlyDiscounted = false): array
    {
        if (empty($ids) || $storeId <= 0) {
            return [];
        }

        $query = Product::query()
            ->whereIn('products.id', $ids)
            ->purchasableInStoreExists($storeId);

        if ($onlyDiscounted) {
            $query->where(function ($q) use ($storeId) {
                $q->whereHas('storeProducts', fn ($sq) => $sq
                    ->where('store_products.store_id', $storeId)
                    ->where('store_products.is_active', true)
                    ->where('store_products.price', '>', 0)
                    ->whereNotNull('store_products.price_old'))
                    ->orWhereHas('activePromotions');
            });
        }

        return $query->pluck('products.id')->flip()->all();
    }

    /**
     * Множество id товаров, доступных хотя бы в одном из магазинов сети,
     * доступных пользователю. Нужно там, где магазина контекста нет:
     * глобальный поиск без адреса и подсказки.
     *
     * Проверки карточки делает purchasable() (в сетевом режиме он уже смотрит
     * на офферы), а привязку к разрешённым магазинам и остаток — условия ниже.
     * Исключения из проверки остатка те же, что у {@see Product::scopePurchasable()}:
     * вариативный родитель, stock_mode=ignore и магазин с ignore_stock.
     *
     * $onlyDiscounted — фильтр «только со скидкой» без контекста магазина:
     * годится любой валидный оффер доступного магазина с price_old либо
     * активная акция карточки (та же логика, что у каталога без контекста).
     *
     * Вызывать только при включённом network_catalog.
     *
     * @param  array<int>  $ids
     * @param  array<int>  $storeIds
     * @return array<int, int> карта [id => 1]
     */
    private function networkValidProductIds(array $ids, array $storeIds, bool $onlyDiscounted = false): array
    {
        if (empty($ids) || empty($storeIds)) {
            return [];
        }

        $offerInAllowedStores = fn ($q) => $q
            ->whereIn('store_products.store_id', $storeIds)
            ->where('store_products.is_active', true)
            ->where('store_products.price', '>', 0);

        $query = Product::query()
            ->whereIn('products.id', $ids)
            ->purchasable()
            ->whereHas('storeProducts', $offerInAllowedStores);

        if (! $this->moduleConfig->isPreordersEnabled()) {
            $query->where(function ($q) use ($offerInAllowedStores, $storeIds) {
                $q->whereHas('storeProducts', fn ($sq) => $offerInAllowedStores($sq)
                    ->whereRaw(Product::minSaleStockSql('store_products.quantity')))
                    ->orWhere('products.is_configurable', true)
                    ->orWhere('products.stock_mode', 'ignore')
                    ->orWhereHas('storeProducts.store', fn ($s) => $s
                        ->whereIn('stores.id', $storeIds)
                        ->where('ignore_stock', true));
            });
        }

        if ($onlyDiscounted) {
            $query->where(function ($q) use ($offerInAllowedStores) {
                $q->whereHas('storeProducts', fn ($sq) => $offerInAllowedStores($sq)
                    ->whereNotNull('store_products.price_old'))
                    ->orWhereHas('activePromotions');
            });
        }

        return $query->pluck('products.id')->flip()->all();
    }

    /**
     * Пересортировать id товаров по выбору пользователя (popular/price_asc/price_desc/discount/newest/name).
     *
     * Meilisearch отдаёт id уже в порядке релевантности, а его sortableAttributes
     * (config/meilisearch.php) не покрывают popular/discount, поэтому сортировка
     * не может быть native-Meili — она делается здесь, на уже полученном и
     * провалидированном наборе id (до пагинации, иначе порядок был бы верным
     * только внутри страницы, но не между страницами). Семантика match-веток
     * скопирована из {@see \App\Http\Controllers\Api\V1\ProductController::applySorting()},
     * который сортирует то же самое поле для каталога (DB-listing). Это лёгкий
     * id-only запрос поверх уже отобранного множества id, а не мутация
     * существующего Eloquent query builder — здесь нет билдера, который можно
     * было бы переиспользовать: список id уже получен из Meili.
     *
     * @param  array<int>  $ids
     * @param  int|null  $storeId  магазин-источник цены в сетевом режиме (null — легаси)
     * @return array<int>
     */
    private function sortProductIds(array $ids, string $sort, ?int $storeId = null): array
    {
        if (empty($ids) || ! in_array($sort, self::STORE_SORTS, true)) {
            return $ids;
        }

        if ($storeId !== null && $this->moduleConfig->isNetworkCatalogEnabled()) {
            return $this->sortProductIdsByStoreOffer($ids, $sort, $storeId);
        }

        $query = Product::query()->whereIn('id', $ids);

        match ($sort) {
            'popular' => $query->withCount('orderItems')->orderByDesc('order_items_count'),
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'discount' => $query
                ->orderByRaw('CASE WHEN price_old IS NOT NULL AND price_old > 0 THEN (price_old - price) * 100 / price_old ELSE 0 END DESC'),
            'newest' => $query->orderByDesc('created_at'),
            'name' => $query->orderBy('title')->orderBy('id'),
        };

        return $query->pluck('id')->all();
    }

    /**
     * Сортировка выдачи магазина в сетевом режиме: products.price/price_old
     * всегда нулевые, поэтому сортируем по колонкам присоединённого оффера
     * (store_products). Семантика веток та же, что у легаси-сортировки и у
     * каталога ({@see \App\Http\Controllers\Api\V1\ProductController::applySorting()}).
     *
     * @param  array<int>  $ids
     * @return array<int>
     */
    private function sortProductIdsByStoreOffer(array $ids, string $sort, int $storeId): array
    {
        $query = Product::query()
            ->whereIn('products.id', $ids)
            ->joinStoreOffer($storeId);

        match ($sort) {
            'popular' => $query->withCount('orderItems')->orderByDesc('order_items_count'),
            'price_asc' => $query->orderBy('store_products.price'),
            'price_desc' => $query->orderByDesc('store_products.price'),
            'discount' => $query
                ->orderByRaw('CASE WHEN store_products.price_old IS NOT NULL AND store_products.price_old > 0 THEN (store_products.price_old - store_products.price) * 100 / store_products.price_old ELSE 0 END DESC'),
            'newest' => $query->orderByDesc('products.created_at'),
            'name' => $query->orderBy('products.title')->orderBy('products.id'),
        };

        return $query->pluck('products.id')->all();
    }

    /**
     * Загрузить товары из БД с сохранением порядка.
     *
     * В сетевом режиме дополнительно подгружается оффер магазина контекста —
     * из него ProductResource берёт цену и остаток ({@see Product::storeContext()}).
     * $storeId = 0/null — контекста нет: видимость считается по сети, а ценой
     * становится лучший оффер ({@see StoreOfferContext::eagerLoad()}).
     *
     * @param  array<int>  $ids
     * @param  int|null  $storeId  магазин контекста в сетевом режиме (null — легаси)
     * @return Collection<int, Product>
     */
    private function loadProducts(array $ids, ?int $storeId = null): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        $network = $this->moduleConfig->isNetworkCatalogEnabled();
        $contextStoreId = $network ? (int) ($storeId ?? 0) : 0;

        $products = Product::query()
            ->whereIn('products.id', $ids)
            ->when(
                $network && $contextStoreId > 0,
                fn ($q) => $q->purchasableInStoreExists($contextStoreId),
                fn ($q) => $q->active()->inStock()->hasPrice(),
            )
            ->with(array_merge(['images', 'activePromotions'], StoreOfferContext::eagerLoad($contextStoreId)))
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
            ->get()
            ->keyBy('id');

        // Сохраняем порядок из Meilisearch
        return collect($ids)
            ->map(fn ($id) => $products->get($id))
            ->filter()
            ->keyBy('id');
    }

    /**
     * Построить дополнительные фильтры.
     *
     * @return array<string>
     */
    private function buildFilters(?bool $onlyDiscounted, ?bool $sugarFree, ?int $brandId = null, ?int $categoryId = null): array
    {
        $filters = [];

        // Сетевой каталог: has_discount документа посчитан по колонкам якорной
        // карточки, где price_old всегда пуст, а акций у неё нет — фильтр отсёк
        // бы всю выдачу. Скидку проверяет БД по офферу магазина
        // (см. storeValidProductIds()/networkValidProductIds()).
        if ($onlyDiscounted === true && ! $this->moduleConfig->isNetworkCatalogEnabled()) {
            $filters[] = 'has_discount = true';
        }

        if ($sugarFree === true) {
            $filters[] = 'sugar_free = true';
        }

        if ($brandId !== null) {
            $filters[] = "brand_id = {$brandId}";
        }

        if ($categoryId !== null) {
            $filters[] = "category_ids = {$categoryId}";
        }

        return $filters;
    }

    /**
     * Список id магазинов, доступных пользователю. Приоритет — явный магазин
     * из запроса (адрес тогда не учитывается), затем активный адрес
     * (через AvailableStoreService), fallback — активные магазины нужного типа
     * (B2B/B2C) по роли пользователя.
     *
     * @return array<int>
     */
    private function resolveAllowedStoreIds(?Address $address, ?User $user, ?int $explicitStoreId = null): array
    {
        if ($explicitStoreId > 0) {
            return [(int) $explicitStoreId];
        }

        if ($address !== null) {
            return $this->availableStoreService
                ->getAvailableStores($address, $user)
                ->pluck('id')
                ->all();
        }

        return Store::query()
            ->active()
            ->forUserType($user)
            ->pluck('id')
            ->all();
    }

    /**
     * Ключ кеша для поиска.
     */
    private function getCacheKey(string $query, int $page, int $perPage, int $productsPerStore, bool $isB2b = false, ?bool $onlyDiscounted = null, ?bool $sugarFree = null, ?int $brandId = null, ?int $categoryId = null, int $categoriesLimit = 5): string
    {
        $hash = md5($query);
        $type = $isB2b ? 'b2b' : 'b2c';
        $discountedFlag = $onlyDiscounted ? '1' : '0';
        $sugarFreeFlag = $sugarFree ? '1' : '0';
        $brandFlag = $brandId ?? '0';
        $categoryFlag = $categoryId ?? '0';

        // showAdult обязателен в ключе: без него выдача 18+ пользователя
        // закешировалась бы для гостя (и наоборот)
        $adultFlag = $this->showAdult ? '1' : '0';

        return "search:{$hash}:{$page}:{$perPage}:{$productsPerStore}:{$type}:{$discountedFlag}:{$sugarFreeFlag}:{$brandFlag}:{$categoryFlag}:{$categoriesLimit}:{$adultFlag}";
    }

    /**
     * Ключ кеша плоского marketplace-поиска. Отдельный префикс, чтобы не
     * пересекаться со стандартной (группированной) выдачей при переключении
     * режима в пределах TTL кеша.
     */
    private function getMarketplaceCacheKey(string $query, int $page, int $perPage, bool $isB2b = false, ?bool $onlyDiscounted = null, ?bool $sugarFree = null, ?int $brandId = null, ?int $categoryId = null, int $categoriesLimit = 5, ?int $addressId = null): string
    {
        $hash = md5($query);
        $type = $isB2b ? 'b2b' : 'b2c';
        $discountedFlag = $onlyDiscounted ? '1' : '0';
        $sugarFreeFlag = $sugarFree ? '1' : '0';
        $brandFlag = $brandId ?? '0';
        $categoryFlag = $categoryId ?? '0';
        // Адрес влияет на набор доступных магазинов → обязан быть в ключе,
        // иначе пользователи с разными адресами делят одну кеш-запись.
        $addressFlag = $addressId ?? '0';

        $adultFlag = $this->showAdult ? '1' : '0';

        return "search:flat:{$hash}:{$page}:{$perPage}:{$type}:{$discountedFlag}:{$sugarFreeFlag}:{$brandFlag}:{$categoryFlag}:{$categoriesLimit}:{$addressFlag}:{$adultFlag}";
    }

    /**
     * Ключ кеша плоского сетевого поиска. Отдельный префикс — чтобы выдача не
     * пересекалась ни со стандартной (группированной), ни с marketplace при
     * переключении режима в пределах TTL. Адрес обязателен в ключе: от него
     * зависит магазин контекста, а значит и цены, и сам состав выдачи.
     */
    private function getNetworkCacheKey(string $query, int $page, int $perPage, bool $isB2b = false, ?bool $onlyDiscounted = null, ?bool $sugarFree = null, ?int $brandId = null, ?int $categoryId = null, int $categoriesLimit = 5, ?int $addressId = null, ?int $storeId = null): string
    {
        $hash = md5($query);
        $type = $isB2b ? 'b2b' : 'b2c';
        $discountedFlag = $onlyDiscounted ? '1' : '0';
        $sugarFreeFlag = $sugarFree ? '1' : '0';
        $brandFlag = $brandId ?? '0';
        $categoryFlag = $categoryId ?? '0';
        $addressFlag = $addressId ?? '0';
        $storeFlag = $storeId ?? '0';

        $adultFlag = $this->showAdult ? '1' : '0';

        return "search:network:{$hash}:{$page}:{$perPage}:{$type}:{$discountedFlag}:{$sugarFreeFlag}:{$brandFlag}:{$categoryFlag}:{$categoriesLimit}:{$addressFlag}:{$storeFlag}:{$adultFlag}";
    }
}
