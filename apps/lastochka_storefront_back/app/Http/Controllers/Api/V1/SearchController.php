<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchProductsInStoreRequest;
use App\Http\Requests\Api\V1\SearchProductsRequest;
use App\Http\Requests\Api\V1\SearchRecommendationsRequest;
use App\Http\Requests\Api\V1\SearchStoreFiltersRequest;
use App\Http\Requests\Api\V1\SearchSuggestionsRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\StoreSearchResultResource;
use App\Models\Address;
use App\Models\Store;
use App\Services\Catalog\CatalogFilterCriteria;
use App\Services\Search\SearchQueryLogger;
use App\Services\Search\SearchService;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;

#[Group('Search', weight: 14)]
final class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly ModuleConfigService $moduleConfigService,
        private readonly SearchQueryLogger $queryLogger,
    ) {}

    /**
     * Поиск товаров
     *
     * Стандартный режим: полнотекстовый поиск товаров с группировкой результатов
     * по магазинам. Магазины сортируются по количеству найденных товаров (desc).
     * Поиск осуществляется только по магазинам, доступным для активного адреса клиента.
     * `total` — количество магазинов в выдаче, а не количество товаров; `hasMore`
     * означает наличие следующей страницы магазинов. Количество найденных товаров
     * в магазине — в поле `totalInStore` элемента `data`.
     *
     * Сетевой каталог (модуль `network_catalog`): сеть — единый каталог,
     * магазины различаются только ценой и остатком, поэтому выдача тоже
     * плоская — `data: ProductResource[]` в порядке релевантности, без
     * `store`, `products` и `totalInStore`. `total` — количество найденных
     * товаров, `hasMore` — наличие следующей страницы товаров, пагинация по
     * товарам (`productsPerStore` игнорируется). Цена и остаток берутся из
     * магазина активного адреса, а без адреса — из лучшего предложения сети;
     * товары, которых нет в доступных магазинах, в выдачу не попадают.
     *
     * Дополнительно возвращает секцию `categories` — категории, название которых
     * матчится с запросом (по аналогии с METRO). Секция не пагинируется и
     * возвращается на каждой странице; наполняется даже при нуле найденных товаров
     * и при недоступном Meilisearch.
     *
     * @response array{hasMore: bool, data: StoreSearchResultResource[]|ProductResource[], total: int, categories: CategoryResource[]}
     */
    #[QueryParameter('query', description: 'Поисковый запрос (2-255 символов)', type: 'string', required: true, example: 'Борщ')]
    #[QueryParameter('page', description: 'Номер страницы (пагинация по магазинам, min: 1)', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество магазинов на странице (1-50)', type: 'int', default: 10, example: 10)]
    #[QueryParameter('productsPerStore', description: 'Макс. количество товаров на магазин (1-50)', type: 'int', default: 10, example: 10)]
    #[QueryParameter('onlyDiscounted', description: 'Только товары со скидкой. Устаревший алиас: only_discounted', type: 'boolean', required: false, example: true)]
    #[QueryParameter('sugarFree', description: 'Только товары без сахара. Устаревший алиас: sugar_free', type: 'boolean', required: false, example: true)]
    #[QueryParameter('brandId', description: 'Фильтр по бренду. Устаревший алиас: brand_id', type: 'int', required: false, example: 1)]
    #[QueryParameter('categoryId', description: 'Фильтр по категории. Устаревший алиас: category_id', type: 'int', required: false, example: 5)]
    #[QueryParameter('categoriesLimit', description: 'Размер секции categories: значения больше 20 усекаются до 20, 0 отключает секцию. Устаревший алиас: categories_limit', type: 'int', default: 5, example: 5)]
    #[QueryParameter('storeId', description: 'ID магазина для цены/остатка и секции categories (сетевой каталог); активный адрес при этом не учитывается. По умолчанию — магазин активного адреса. Вне сетевого каталога игнорируется', type: 'int', required: false, example: 1)]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    // Плоская выдача без группировки по магазинам (`data: ProductResource[]`,
    // пагинация по товарам) используется также в режиме скрытого маркетплейса.
    public function products(SearchProductsRequest $request): JsonResponse
    {
        $this->searchService->withAdultVisibility(
            app(\App\Services\Catalog\AdultContentPolicy::class)->showAdult($request),
        );

        if ($this->moduleConfigService->isMarketplaceMode()) {
            return $this->productsMarketplace($request);
        }

        if ($this->moduleConfigService->isNetworkCatalogEnabled()) {
            return $this->productsNetwork($request);
        }

        return $this->productsStandard($request);
    }

    /**
     * Стандартный (группированный по магазинам) поиск. Поведение других форков.
     */
    private function productsStandard(SearchProductsRequest $request): JsonResponse
    {
        // Получаем активный адрес только для авторизованных пользователей
        $activeAddress = null;
        if ($request->user()) {
            $activeAddress = Address::query()
                ->forUser($request->user())
                ->active()
                ->first();
        }

        $result = $this->searchService->search(
            query: $request->getQuery(),
            page: $request->getPage(),
            perPage: $request->getPerPage(),
            productsPerStore: $request->getProductsPerStore(),
            onlyDiscounted: $request->getOnlyDiscounted(),
            sugarFree: $request->getSugarFree(),
            address: $activeAddress,
            user: $request->user(),
            brandId: $request->getBrandId(),
            categoryId: $request->getCategoryId(),
            categoriesLimit: $request->getCategoriesLimit()
        );

        return response()->json([
            'hasMore' => $result['hasMore'],
            'data' => StoreSearchResultResource::collection($result['stores']),
            'total' => $result['total'],
            'categories' => CategoryResource::collection($result['categories']),
        ]);
    }

    /**
     * Плоский поиск для сетевого каталога: сеть — единый каталог, магазины
     * различаются только офферами, поэтому группировать выдачу нечем.
     * Цена и остаток — из оффера магазина активного адреса, без адреса —
     * из лучшего оффера сети.
     */
    private function productsNetwork(SearchProductsRequest $request): JsonResponse
    {
        $activeAddress = null;
        if ($request->user()) {
            $activeAddress = Address::query()
                ->forUser($request->user())
                ->active()
                ->first();
        }

        $result = $this->searchService->searchNetwork(
            query: $request->getQuery(),
            page: $request->getPage(),
            perPage: $request->getPerPage(),
            onlyDiscounted: $request->getOnlyDiscounted(),
            sugarFree: $request->getSugarFree(),
            address: $activeAddress,
            user: $request->user(),
            brandId: $request->getBrandId(),
            categoryId: $request->getCategoryId(),
            categoriesLimit: $request->getCategoriesLimit(),
            storeId: $request->getStoreId(),
        );

        return response()->json([
            'hasMore' => $result['hasMore'],
            'data' => ProductResource::collection($result['products']),
            'total' => $result['total'],
            'categories' => CategoryResource::collection($result['categories']),
        ]);
    }

    /**
     * Плоский поиск для marketplace режима: клиент не должен видеть
     * магазины-партнёры, поэтому в ответе нет ни группировки, ни store.
     */
    private function productsMarketplace(SearchProductsRequest $request): JsonResponse
    {
        $activeAddress = null;
        if ($request->user()) {
            $activeAddress = Address::query()
                ->forUser($request->user())
                ->active()
                ->first();
        }

        $result = $this->searchService->searchMarketplace(
            query: $request->getQuery(),
            page: $request->getPage(),
            perPage: $request->getPerPage(),
            onlyDiscounted: $request->getOnlyDiscounted(),
            sugarFree: $request->getSugarFree(),
            address: $activeAddress,
            user: $request->user(),
            brandId: $request->getBrandId(),
            categoryId: $request->getCategoryId(),
            categoriesLimit: $request->getCategoriesLimit()
        );

        return response()->json([
            'hasMore' => $result['hasMore'],
            'data' => ProductResource::collection($result['products']),
            'total' => $result['total'],
            'categories' => CategoryResource::collection($result['categories']),
        ]);
    }

    /**
     * Поиск товаров в магазине
     *
     * Поиск товаров в конкретном магазине с пагинацией.
     * Используется когда пользователь хочет увидеть все найденные товары в магазине.
     *
     * Bearer-токен здесь не учитывается: отбор идёт по указанному магазину, без
     * фильтрации по доступности адреса и по типу магазина. `total` — общее количество
     * найденных товаров в магазине, `hasMore` — наличие следующей страницы товаров.
     *
     * Дополнительно возвращает секцию `categories` — фасеты по фактической выдаче:
     * категории, в которых лежат найденные товары, с количеством товаров в каждой.
     * Предназначена для чипов-фильтров над лентой: id элемента пригоден для передачи
     * обратно в `categoryId`. В отличие от `categories` у `/search/products`, здесь
     * категории не ищутся по названию, а считаются по товарам, поэтому чип не может
     * привести к пустому экрану.
     *
     * Секция считается по всей выдаче, а не по текущей странице, и приходит
     * одинаковой на каждой странице пагинации. Сортировка по `count` desc, при
     * равенстве — по названию. Не более 30 элементов. Переданный `categoryId`
     * на секцию не влияет (иначе после тапа по чипу нельзя было бы переключиться
     * на соседнюю категорию), остальные фильтры влияют. Пустая выдача или
     * недоступный поиск дают `[]`.
     *
     * Выбор на экране «Фильтр» передаётся теми же параметрами, что и в листинге
     * категории: `priceMin`, `priceMax`, `filter[code]=value_code1,value_code2`
     * (AND между атрибутами, OR внутри одного; для динамических чекбоксов
     * `filter[<флаг>]=1`). Цена в сетевом каталоге сравнивается по цене товара
     * в этом магазине. Выбор влияет и на `categories`.
     *
     * При `countOnly=1` ответ содержит только `{total: N}` — без `data`,
     * `categories` и пагинации (кнопка «Показать N товаров»).
     *
     * @response array{hasMore: bool, data: ProductResource[], total: int, categories: array{id: int, name: string, count: int}[]}
     */
    #[QueryParameter('query', description: 'Поисковый запрос (2-255 символов)', type: 'string', required: true, example: 'Борщ')]
    #[QueryParameter('page', description: 'Номер страницы (min: 1)', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество товаров на странице (1-100)', type: 'int', default: 20, example: 20)]
    #[QueryParameter('onlyDiscounted', description: 'Только товары со скидкой. Устаревший алиас: only_discounted', type: 'boolean', required: false, example: true)]
    #[QueryParameter('sugarFree', description: 'Только товары без сахара. Устаревший алиас: sugar_free', type: 'boolean', required: false, example: true)]
    #[QueryParameter('brandId', description: 'Фильтр по бренду. Устаревший алиас: brand_id', type: 'int', required: false, example: 1)]
    #[QueryParameter('categoryId', description: 'Фильтр по категории. Устаревший алиас: category_id', type: 'int', required: false, example: 5)]
    #[QueryParameter('sort', description: 'Сортировка: relevance, name, popular, price_asc, price_desc, discount, newest. Без параметра (и при нераспознанном значении) — relevance, порядок релевантности Meilisearch', type: 'string', required: false, example: 'price_asc')]
    #[QueryParameter('priceMin', description: 'Минимальная цена', type: 'int', required: false, example: 100)]
    #[QueryParameter('priceMax', description: 'Максимальная цена', type: 'int', required: false, example: 5000)]
    #[QueryParameter('filter', description: 'Фильтры по select-атрибутам: filter[code]=value_code1,value_code2 (AND между атрибутами, OR внутри одного). Для динамических чекбоксов товара — filter[<флаг>]=1 (значения "1"/"true")', type: 'array', required: false, example: ['manufacturer' => 'schneider,abb'])]
    #[QueryParameter('countOnly', description: 'Вернуть только {total: N} без data/categories/пагинации (для кнопки "Показать N товаров"). Значения: 0, 1, true, false', type: 'boolean', required: false, example: true)]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function productsInStore(SearchProductsInStoreRequest $request, Store $store): JsonResponse
    {
        $showAdult = app(\App\Services\Catalog\AdultContentPolicy::class)->showAdult($request);
        $this->searchService->withAdultVisibility($showAdult);

        $criteria = CatalogFilterCriteria::fromRequest($request);

        if ($request->isCountOnly()) {
            return response()->json([
                'total' => $this->searchService->countInStore(
                    query: $request->getQuery(),
                    storeId: $store->id,
                    onlyDiscounted: $request->getOnlyDiscounted(),
                    sugarFree: $request->getSugarFree(),
                    brandId: $request->getBrandId(),
                    categoryId: $request->getCategoryId(),
                    criteria: $criteria,
                ),
            ]);
        }

        $result = $this->searchService->searchInStore(
            query: $request->getQuery(),
            storeId: $store->id,
            page: $request->getPage(),
            perPage: $request->getPerPage(),
            onlyDiscounted: $request->getOnlyDiscounted(),
            sugarFree: $request->getSugarFree(),
            brandId: $request->getBrandId(),
            categoryId: $request->getCategoryId(),
            sort: $request->getSort(),
            criteria: $criteria,
        );

        // Лог поиска для отчёта «искали, но не нашли»: только набранный текст
        // (первая страница без фильтров экрана), листание и фильтры — тот же спрос.
        if ($request->getPage() === 1
            && $criteria->isEmpty()
            && $request->getCategoryId() === null
            && $request->getBrandId() === null
            && $request->getOnlyDiscounted() !== true
            && $request->getSugarFree() !== true
        ) {
            $this->queryLogger->recordAfterResponse($request, $request->getQuery(), $store->id, $result['total'], $showAdult);
        }

        return response()->json([
            'hasMore' => $result['hasMore'],
            'data' => ProductResource::collection($result['products']),
            'total' => $result['total'],
            'categories' => $result['categories'],
        ]);
    }

    /**
     * Фильтры поиска в магазине
     *
     * Набор фильтров экрана «Фильтр», открытого из выдачи поиска в магазине.
     * Формат тот же, что у фильтров категории (`category.filters`):
     * - секция `{code:"price", name, type:"range", min, max}` — всегда первой;
     * - по секции на select-атрибут: `{code, name, type:"checkbox", values:[{id, code, name, count, available}]}`;
     * - флаг-фасеты динамических чекбоксов: `{code, name, type:"flag", count, available}`.
     *
     * Состав секций и значений считается по найденным товарам магазина с учётом
     * `query`, `categoryId`, `brandId`, `onlyDiscounted`, `sugarFree` и видимости
     * 18+, но без выбора на экране фильтра — недоступные значения не пропадают.
     * Выбор (`priceMin`, `priceMax`, `filter[]`) влияет на счётчики: `count`
     * значения — сколько товаров останется, если его выбрать (с выбором в
     * остальных группах, без выбора в своей); `available` — `count > 0`.
     * `min`/`max` цены — по всем группам, кроме самой цены, округлены наружу
     * до целых. В сетевом каталоге цена — цена товара в этом магазине.
     *
     * Bearer-токен учитывается только для видимости 18+. Недоступный поиск
     * даёт одну секцию цены с нулями.
     *
     * @response array{data: array<array{code: string, name: string, type: string, min?: int, max?: int, count?: int, available?: bool, values?: array<array{id: int, code: string, name: string, count: int, available: bool}>}>}
     */
    #[QueryParameter('query', description: 'Поисковый запрос (2-255 символов)', type: 'string', required: true, example: 'Вино')]
    #[QueryParameter('categoryId', description: 'Фильтр по категории (чип над выдачей). Устаревший алиас: category_id', type: 'int', required: false, example: 5)]
    #[QueryParameter('brandId', description: 'Фильтр по бренду. Устаревший алиас: brand_id', type: 'int', required: false, example: 1)]
    #[QueryParameter('onlyDiscounted', description: 'Только товары со скидкой. Устаревший алиас: only_discounted', type: 'boolean', required: false, example: true)]
    #[QueryParameter('sugarFree', description: 'Только товары без сахара. Устаревший алиас: sugar_free', type: 'boolean', required: false, example: true)]
    #[QueryParameter('priceMin', description: 'Выбранная минимальная цена', type: 'int', required: false, example: 100)]
    #[QueryParameter('priceMax', description: 'Выбранная максимальная цена', type: 'int', required: false, example: 5000)]
    #[QueryParameter('filter', description: 'Выбранные значения: filter[code]=value_code1,value_code2; для динамических чекбоксов — filter[<флаг>]=1', type: 'array', required: false, example: ['manufacturer' => 'schneider,abb'])]
    #[QueryParameter('showAdult', description: 'Гость: учитывать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function filtersInStore(SearchStoreFiltersRequest $request, Store $store): JsonResponse
    {
        $this->searchService->withAdultVisibility(
            app(\App\Services\Catalog\AdultContentPolicy::class)->showAdult($request),
        );

        return response()->json([
            'data' => $this->searchService->filtersInStore(
                query: $request->getQuery(),
                storeId: $store->id,
                criteria: CatalogFilterCriteria::fromRequest($request),
                onlyDiscounted: $request->getOnlyDiscounted(),
                sugarFree: $request->getSugarFree(),
                brandId: $request->getBrandId(),
                categoryId: $request->getCategoryId(),
            ),
        ]);
    }

    /**
     * Рекомендации для экрана поиска
     *
     * Возвращает подборку товаров для двух состояний экрана поиска:
     * начальное (пустое поле ввода) и zero-results (по запросу ничего не найдено).
     *
     * Источник — флаг `products.on_search_page` (управляется в админке).
     * Если помеченных товаров меньше чем `limit`, подборка добивается
     * свежими активными товарами из тех же доступных магазинов.
     *
     * При передаче Bearer-токена результат фильтруется по магазинам,
     * доступным для активного адреса пользователя. Если доступных магазинов нет,
     * ответ — пустая подборка.
     *
     * `total` — количество товаров в текущем ответе, а не общее число доступных.
     *
     * @response array{data: ProductResource[], total: int}
     */
    #[QueryParameter('limit', description: 'Макс. количество товаров (1-50)', type: 'int', default: 20, example: 20)]
    #[QueryParameter('storeId', description: 'ID магазина для цены/остатка и доступности товаров (сетевой каталог); активный адрес при этом не учитывается. По умолчанию — магазин активного адреса', type: 'int', required: false, example: 1)]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function recommendations(SearchRecommendationsRequest $request): JsonResponse
    {
        $this->searchService->withAdultVisibility(
            app(\App\Services\Catalog\AdultContentPolicy::class)->showAdult($request),
        );

        $activeAddress = null;
        if ($request->user()) {
            $activeAddress = Address::query()
                ->forUser($request->user())
                ->active()
                ->first();
        }

        $result = $this->searchService->getRecommendations(
            limit: $request->getLimit(),
            address: $activeAddress,
            user: $request->user(),
            explicitStoreId: $request->getStoreId(),
        );

        return response()->json([
            'data' => ProductResource::collection($result['products']),
            'total' => $result['total'],
        ]);
    }

    /**
     * Подсказки для поиска
     *
     * Возвращает список подсказок для автодополнения поискового запроса.
     *
     * Без `structured` (или `structured=false`): плоский массив строк-подсказок.
     * С `structured=true`: объект с секциями `categories`, `brands`, `products` для Siri-style UI.
     * Секция `products` — строки-названия товаров, а не карточки. `logoImage` бренда —
     * объект `{url}` с полным URL логотипа либо null. Элемент `categories` —
     * `{id, name, slug}`, `slug` нужен для перехода в каталог по категории.
     *
     * Доступные магазины: если передан `storeId` — только он (адрес не учитывается,
     * как у `/search/products`); иначе магазины активного адреса пользователя,
     * а без адреса — активные магазины с учётом правил доступа.
     *
     * Минимальная длина запроса здесь 1 символ (у /search/products — 2).
     */
    #[ScrambleResponse(200, type: 'array<string>|array{categories: array<array{id: int, name: string, slug: string}>, brands: array<array{id: int, title: string, logoImage: array{url: string}|null}>, products: array<string>}', description: 'Плоский array<string> (structured=false или не передан); либо объект с секциями categories/brands/products (structured=true).')]
    #[QueryParameter('query', description: 'Начало поискового запроса (1-255 символов)', type: 'string', required: true, example: 'Бор')]
    #[QueryParameter('limit', description: 'Макс. количество подсказок (1-20)', type: 'int', default: 10, example: 10)]
    #[QueryParameter('structured', description: 'Вернуть структурированный ответ с категориями и брендами. Допустимые значения: 0, 1, true, false', type: 'boolean', required: false, example: true)]
    #[QueryParameter('storeId', description: 'ID выбранного магазина: подсказки и категории резолвятся по нему, активный адрес не учитывается. По умолчанию — магазины активного адреса', type: 'int', required: false, example: 1)]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function suggestions(SearchSuggestionsRequest $request): JsonResponse
    {
        $this->searchService->withAdultVisibility(
            app(\App\Services\Catalog\AdultContentPolicy::class)->showAdult($request),
        );

        $user = auth('sanctum')->user();
        $storeId = $request->getStoreId();

        // При явном storeId адрес не участвует в отборе — не грузим его зря.
        $activeAddress = null;
        if ($user !== null && $storeId === null) {
            $activeAddress = Address::query()
                ->forUser($user)
                ->active()
                ->first();
        }

        $suggestions = $this->searchService->getSuggestions(
            query: $request->getQuery(),
            limit: $request->getLimit(),
            user: $user,
            structured: $request->isStructured(),
            address: $activeAddress,
            storeId: $storeId,
        );

        return response()->json($suggestions);
    }
}
