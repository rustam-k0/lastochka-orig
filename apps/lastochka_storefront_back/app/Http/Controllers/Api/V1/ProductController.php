<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductDocumentResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Services\Catalog\AdultContentPolicy;
use App\Services\Catalog\CatalogFilterCriteria;
use App\Services\Catalog\CatalogFilterQuery;
use App\Services\Catalog\StoreOfferContext;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Pickup\PickupSlotService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Support\Facades\Cache;

#[Group('Products', weight: 4)]
final class ProductController extends Controller
{
    public function __construct(
        private DeliveryClientInterface $deliveryClient,
        private PickupSlotService $pickupSlotService,
        private CatalogFilterQuery $filterQuery,
    ) {}
    /**
     * Товары категории
     *
     * Возвращает список товаров указанной категории с пагинацией.
     * Для родительской категории агрегирует товары всего поддерева
     * (сама категория + вложенные подкатегории на любую глубину, без дублей).
     * `category=0` — товары всех категорий магазина сразу, без фильтра по категории.
     * Поддерживает сортировку и фильтрацию по цене, атрибутам и флагам.
     *
     * Deprecated snake_case-варианты `price_min`, `price_max`, `count_only`,
     * `city_id`, `only_discounted`, `sugar_free` принимаются как алиасы
     * одноимённых camelCase-параметров.
     *
     * При `countOnly=1` ответ содержит только `{total: N}` — без `data`
     * и без пагинации.
     *
     * @response array{hasMore: bool, data: ProductResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    #[QueryParameter('sort', description: 'Сортировка: popular, price_asc, price_desc, discount, newest, name. relevance в категории работает как name (поискового запроса нет). Без параметра (и при нераспознанном значении) — по названию товара', type: 'string', required: false, example: 'price_asc')]
    #[QueryParameter('priceMin', description: 'Минимальная цена', type: 'int', required: false, example: 100)]
    #[QueryParameter('priceMax', description: 'Максимальная цена', type: 'int', required: false, example: 5000)]
    #[QueryParameter('filter', description: 'Фильтры по select-атрибутам: filter[code]=value_code1,value_code2 (AND между атрибутами, OR внутри одного). Для динамических чекбоксов товара — filter[<флаг>]=1 (значения "1"/"true")', type: 'array', required: false, example: ['manufacturer' => 'schneider,abb'])]
    #[QueryParameter('countOnly', description: 'Вернуть только {total: N} без data/пагинации (для кнопки "Показать N товаров")', type: 'boolean', required: false, example: true)]
    #[QueryParameter('cityId', description: 'ID города для расчёта сроков доставки/самовывоза', type: 'int', required: false, example: 1)]
    #[QueryParameter('onlyDiscounted', description: 'Только товары со скидкой', type: 'boolean', required: false, example: true)]
    #[QueryParameter('sugarFree', description: 'Только товары без сахара', type: 'boolean', required: false, example: true)]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function byCategory(Request $request, Store $store, string $category): JsonResponse
    {
        // category=0 — специальное значение "все категории": без Category-модели
        // и без фильтра по поддереву категорий, но только товары, у которых
        // есть хоть одна категория (товары совсем без категории не попадают).
        $categoryId = (int) $category;
        $categoryModel = $categoryId > 0 ? Category::findOrFail($categoryId) : null;

        return $this->productsByCategory($request, $store, $categoryModel);
    }

    /**
     * Товары категории по slug
     *
     * То же самое, что byCategory, но категория резолвится по slug вместо id.
     * `slug=-` — специальное значение "все категории" (аналог category=0
     * в byCategory): без фильтра по конкретной категории, но только товары,
     * у которых есть хоть одна категория.
     *
     * Deprecated snake_case-варианты `price_min`, `price_max`, `count_only`,
     * `city_id`, `only_discounted`, `sugar_free` принимаются как алиасы
     * одноимённых camelCase-параметров.
     *
     * При `countOnly=1` ответ содержит только `{total: N}` — без `data`
     * и без пагинации.
     *
     * @response array{hasMore: bool, data: ProductResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    #[QueryParameter('sort', description: 'Сортировка: popular, price_asc, price_desc, discount, newest, name. relevance в категории работает как name (поискового запроса нет). Без параметра (и при нераспознанном значении) — по названию товара', type: 'string', required: false, example: 'price_asc')]
    #[QueryParameter('priceMin', description: 'Минимальная цена', type: 'int', required: false, example: 100)]
    #[QueryParameter('priceMax', description: 'Максимальная цена', type: 'int', required: false, example: 5000)]
    #[QueryParameter('filter', description: 'Фильтры по select-атрибутам: filter[code]=value_code1,value_code2 (AND между атрибутами, OR внутри одного). Для динамических чекбоксов товара — filter[<флаг>]=1 (значения "1"/"true")', type: 'array', required: false, example: ['manufacturer' => 'schneider,abb'])]
    #[QueryParameter('countOnly', description: 'Вернуть только {total: N} без data/пагинации (для кнопки "Показать N товаров")', type: 'boolean', required: false, example: true)]
    #[QueryParameter('cityId', description: 'ID города для расчёта сроков доставки/самовывоза', type: 'int', required: false, example: 1)]
    #[QueryParameter('onlyDiscounted', description: 'Только товары со скидкой', type: 'boolean', required: false, example: true)]
    #[QueryParameter('sugarFree', description: 'Только товары без сахара', type: 'boolean', required: false, example: true)]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function byCategorySlug(Request $request, Store $store, string $slug): JsonResponse
    {
        // slug уникален в рамках магазина, не глобально (категория может
        // принадлежать нескольким магазинам через category_store) — без
        // scope по store здесь можно было бы найти чужую категорию с тем
        // же slug. В сетевом режиме дерево общее и scope схлопывается
        // в no-op (см. Category::scopeAttachedToStore()).
        $categoryModel = $slug === '-'
            ? null
            : Category::query()
                ->attachedToStore($store->id)
                ->where('slug', $slug)
                ->firstOrFail();

        return $this->productsByCategory($request, $store, $categoryModel);
    }

    private function productsByCategory(Request $request, Store $store, ?Category $categoryModel): JsonResponse
    {
        // Backward-compat: принимаем deprecated snake_case query-параметры как алиасы camelCase.
        self::aliasSnakeQueryParams($request, [
            'price_min' => 'priceMin',
            'price_max' => 'priceMax',
            'count_only' => 'countOnly',
            'city_id' => 'cityId',
            'only_discounted' => 'onlyDiscounted',
            'sugar_free' => 'sugarFree',
        ]);

        $perPage = min($request->integer('perPage', 20), 100);

        // Сетевой каталог: карточка глобальна, а видимость/цена/остаток товара
        // в этом магазине живут в store_products. purchasableInStore() делает
        // join оффера — фильтры и сортировки ниже читают его колонки напрямую.
        // Оффер дополнительно подгружаем constrained-eager-load'ом: из него
        // ProductResource берёт цену/остаток (контракт storeContext()).
        $networkStoreId = $this->networkStoreId($store->id);

        $query = Product::query()
            ->purchasableInStore($store->id)
            ->adultVisible(app(AdultContentPolicy::class)->showAdult($request))
            ->when(
                $categoryModel,
                fn ($q) => $q->forCategorySubtree($categoryModel),
                fn ($q) => $q->whereHas('categories'),
            )
            ->with('images')
            ->when(
                $networkStoreId !== null,
                fn ($q) => $q->with(self::storeOfferEagerLoad($networkStoreId)),
            )
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)]);

        $this->filterQuery->applyToggles(
            $query,
            $request->boolean('onlyDiscounted'),
            $request->boolean('sugarFree'),
            $networkStoreId,
        );
        $this->filterQuery->applyCriteria($query, CatalogFilterCriteria::fromRequest($request), $networkStoreId);

        if ($request->boolean('countOnly')) {
            return response()->json([
                'total' => (int) $query->toBase()->getCountForPagination(),
            ]);
        }

        $this->applySorting($query, $request->string('sort')->toString(), $networkStoreId);

        $products = $query->paginate($perPage);

        $this->applyDeliveryDates($this->resolveCity($request), $products->getCollection());

        return response()->json([
            'hasMore' => $products->hasMorePages(),
            'data' => ProductResource::collection($products),
            'total' => $products->total(),
        ]);
    }

    /**
     * Город из запроса для расчёта сроков доставки/самовывоза.
     *
     * Возвращает null, если параметр не передан, город не найден или у него
     * нет координат — тогда поля deliveryDate/pickupDate в ответ не попадают.
     * Принимает deprecated snake_case `city_id` как алиас `cityId`.
     */
    private function resolveCity(Request $request): ?City
    {
        self::aliasSnakeQueryParams($request, ['city_id' => 'cityId']);

        if (! $request->filled('cityId')) {
            return null;
        }

        $city = City::find($request->integer('cityId'));

        if (! $city || ! $city->latitude || ! $city->longitude) {
            return null;
        }

        return $city;
    }

    /**
     * Проставить deliveryDate/pickupDate товарам, если город определён.
     *
     * @param  Collection<int, Product>|Product  $products
     */
    private function applyDeliveryDates(?City $city, Collection|Product $products): void
    {
        if ($city === null) {
            return;
        }

        $this->enrichWithDeliveryDates(
            $products instanceof Product ? collect([$products]) : $products,
            $city,
        );
    }

    /**
     * Принять deprecated snake_case query-параметры как алиасы camelCase.
     * Используется на endpoint'ах без FormRequest (где prepareForValidation недоступен).
     *
     * @param  array<string, string>  $aliases  ['snake_case' => 'camelCase']
     */
    private static function aliasSnakeQueryParams(Request $request, array $aliases): void
    {
        foreach ($aliases as $snake => $camel) {
            if ($request->has($snake) && ! $request->has($camel)) {
                $request->merge([$camel => $request->input($snake)]);
            }
        }
    }

    private function enrichWithDeliveryDates(Collection $products, City $city): void
    {
        $storeIds = $products->pluck('store_id')->unique();
        $stores = Store::whereIn('id', $storeIds)->with('schedules')->get()->keyBy('id');

        $dates = [];
        foreach ($stores as $store) {
            $dates[$store->id] = [
                'delivery' => $this->getEarliestDeliveryDate($store, $city),
                'pickup' => $this->getEarliestPickupDate($store),
            ];
        }

        foreach ($products as $product) {
            $storeDates = $dates[$product->store_id] ?? null;
            $product->setAttribute('delivery_date', $storeDates['delivery'] ?? null);
            $product->setAttribute('pickup_date', $storeDates['pickup'] ?? null);
        }
    }

    private function getEarliestDeliveryDate(Store $store, City $city): ?string
    {
        $today = now()->format('Y-m-d');
        $tomorrow = now()->addDay()->format('Y-m-d');

        foreach ([$today, $tomorrow] as $date) {
            $cacheKey = "catalog_delivery:{$city->id}:{$store->code}:{$date}";
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                return $cached ?: null; // empty string = no slots
            }

            try {
                $info = $this->deliveryClient->getSlots(
                    $store->code,
                    (float) $city->latitude,
                    (float) $city->longitude,
                    $date,
                );

                if (! empty($info->slots)) {
                    Cache::put($cacheKey, $date, now()->addMinutes(30));

                    return $date;
                }

                Cache::put($cacheKey, '', now()->addMinutes(30));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function getEarliestPickupDate(Store $store): ?string
    {
        if (! $store->is_pickup_enabled) {
            return null;
        }

        for ($i = 0; $i < 7; $i++) {
            $date = now()->addDays($i)->format('Y-m-d');
            $slots = $this->pickupSlotService->getSlots($store, $date);

            if (! empty($slots)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Магазин, оффер которого используется как источник цены/остатка, либо
     * null в легаси-режиме (там цена лежит в колонках products).
     */
    private function networkStoreId(?int $storeId): ?int
    {
        if ($storeId === null || $storeId <= 0) {
            return null;
        }

        return app(ModuleConfigService::class)->isNetworkCatalogEnabled() ? $storeId : null;
    }

    /**
     * Магазин, в контексте которого отдаём цену/остаток товара: явный
     * `?storeId=` либо первый доступный магазин активного адреса
     * авторизованного пользователя. 0 — контекста нет.
     *
     * Контекст резолвится ТОЛЬКО при включённом сетевом каталоге: при
     * выключенном флаге ручки обязаны отвечать байт-в-байт как раньше,
     * а раньше ни storeId, ни адрес пользователя на карточку и на
     * похожие/сопутствующие товары не влияли.
     */
    private function resolveStoreContextId(Request $request): int
    {
        return app(StoreOfferContext::class)->resolveStoreId($request);
    }

    /**
     * Constrained eager load оффера магазина — источник цены/остатка для
     * ProductResource ({@see StoreOfferContext::eagerLoad()}).
     *
     * @return array<string, mixed>
     */
    private static function storeOfferEagerLoad(int $storeId): array
    {
        return StoreOfferContext::eagerLoad($storeId);
    }

    /**
     * Навесить офферы магазина контекста товарам-добавкам карточки.
     *
     * Каталожная добавка — обычный товар сети: её цена и остаток живут в
     * store_products, а колонки глобальной карточки нулевые. Без этого
     * SupplementValueResource отдаёт price=0/stockQuantity=0, а
     * SupplementResource вырезает добавку из выдачи как «нет на складе».
     */
    private static function attachSupplementOffers(Product $product, int $storeContextId): void
    {
        if (! app(ModuleConfigService::class)->isNetworkCatalogEnabled()
            || ! $product->relationLoaded('supplements')
        ) {
            return;
        }

        $product->supplements->loadMissing('values.product');

        StoreOfferContext::attach(
            $product->supplements
                ->flatMap(fn ($supplement) => $supplement->values)
                ->map(fn ($value) => $value->product)
                ->filter(),
            $storeContextId
        );
    }

    private function applySorting(\Illuminate\Database\Eloquent\Builder $query, string $sort, ?int $networkStoreId = null): void
    {
        // Сетевой каталог: products.price/price_old всегда нулевые — сортируем
        // по колонкам оффера, уже присоединённого join'ом в purchasableInStore().
        // created_at квалифицируем: с join'ом колонка есть в обеих таблицах.
        $network = $networkStoreId !== null;
        $price = $network ? 'store_products.price' : 'products.price';
        $priceOld = $network ? 'store_products.price_old' : 'products.price_old';

        // Каждая ветка заканчивается детерминированным tie-break (ordered():
        // title, id; для newest — id desc). Первичный ключ сортировки почти
        // всегда неуникален (у большинства товаров 0 заказов, одинаковые
        // цены, один created_at у импорта из 1С), а MySQL при LIMIT/OFFSET
        // не гарантирует порядок равных строк между запросами — страница 2
        // повторяла страницу 1, часть товаров не показывалась вовсе.
        match ($sort) {
            'popular' => $query->withCount('orderItems')->orderByDesc('order_items_count')->ordered(),
            'price_asc' => $query->orderBy($price)->ordered(),
            'price_desc' => $query->orderByDesc($price)->ordered(),
            'discount' => $query->orderByRaw(
                "CASE WHEN {$priceOld} IS NOT NULL AND {$priceOld} > 0 THEN ({$priceOld} - {$price}) * 100 / {$priceOld} ELSE 0 END DESC"
            )->ordered(),
            'newest' => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
            // relevance: в листинге категории поискового запроса нет, ранжировать
            // нечем — порядок тот же, что у name (чип приходит из общего экрана
            // фильтра, который открывается и из поиска).
            'name', 'relevance' => $query->ordered(),
            default => $query->ordered(),
        };
    }

    /**
     * Детали товара
     *
     * Возвращает детальную информацию о товаре. Изображения, добавки, атрибуты
     * и категории загружаются всегда, бренд — при включённом модуле «Бренды».
     *
     * Для вариации (`parent_id` заполнен) при включённом модуле вариативных
     * товаров отдаётся активный родитель с загруженными вариантами, а id
     * запрошенного товара попадает в поле `selectedVariantId` внутри `data`.
     * Если активный родитель не найден — отдаётся сам запрошенный товар.
     *
     * Ответ 404 отдаётся, если товар не найден или удалён; если он неактивен;
     * если это вариативный родитель при выключенном модуле вариативных товаров;
     * а также если остаток меньше кванта покупки при выключенном модуле
     * предзаказов и не игнорируемом остатке.
     *
     * При переданном `cityId` в ответ добавляются `deliveryDate` и `pickupDate`
     * — ближайшие даты доставки и самовывоза для магазина товара (строки
     * «Доставка: сегодня» / «Самовывоз: сегодня» на карточке). Без параметра
     * этих полей в ответе нет.
     *
     * При включённом сетевом каталоге цена и остаток отдаются по магазину
     * из `storeId` (либо по активному адресу авторизованного пользователя).
     * Без контекста магазина — значения карточки.
     */
    #[QueryParameter('cityId', description: 'ID города для расчёта сроков доставки/самовывоза', type: 'int', required: false, example: 1)]
    #[QueryParameter('storeId', description: 'ID магазина для цены/остатка (сетевой каталог); по умолчанию — магазин активного адреса', type: 'int', required: false, example: 1)]
    public function show(Request $request, Product $product): ProductResource|JsonResponse
    {
        return $this->showProduct($request, $product);
    }

    /**
     * Детали товара по slug (страница товара на витрине)
     *
     * Полный аналог show() — та же логика вариаций/остатков/дат доставки —
     * только товар резолвится по slug, а не по числовому id. В отличие от
     * категорий (много-ко-многим с магазинами через category_store, там
     * scope по store — это проверка видимости), у товара store_id — обычная
     * колонка, а slug уникализируется глобально в ProductSlugGenerator::taken()
     * (без фильтра по store_id) — так что дополнительный scope по магазину
     * тут ничего не защищает, специально не добавляю.
     */
    #[QueryParameter('cityId', description: 'ID города для расчёта сроков доставки/самовывоза', type: 'int', required: false, example: 1)]
    public function showBySlug(Request $request, string $slug): ProductResource|JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->firstOrFail();

        return $this->showProduct($request, $product);
    }

    private function showProduct(Request $request, Product $product): ProductResource|JsonResponse
    {
        $moduleConfig = app(ModuleConfigService::class);
        $configurableEnabled = $moduleConfig->isConfigurableProductsEnabled();
        $networkEnabled = $moduleConfig->isNetworkCatalogEnabled();
        $city = $this->resolveCity($request);

        if (! $product->is_active) {
            abort(404);
        }

        // Фича выключена — parent'ы недоступны.
        if (!$configurableEnabled && $product->is_configurable) {
            abort(404);
        }

        // Сетевой каталог: остаток карточки всегда 0, реальный остаток —
        // в оффере магазина (store_products). Подгружаем оффер до проверки
        // доступности, чтобы 404 считался по нужному магазину.
        $storeContextId = $this->resolveStoreContextId($request);

        // Оффер грузим всегда: без контекста магазина карточка (и её вариации)
        // отдаёт лучшее предложение сети, а не нулевую цену якорной карточки.
        $variantOfferLoad = self::storeOfferEagerLoad($storeContextId);

        $product->load($variantOfferLoad);

        $storeOffer = null;
        if ($storeContextId > 0) {
            $storeOffer = $product->storeContext();

            // Контекст магазина есть, а оффера в нём нет — товар в этом
            // магазине не продаётся. Симметрично листингу (он такой товар
            // не показывает) и страхует от нулевой цены в корзине.
            //
            // Вариативное семейство проверяется целиком: оффер может быть
            // у родителя, а не у вариации, и наоборот — а запрос вариации
            // всё равно резолвится в родителя. Доступна семья, где валидный
            // оффер есть хотя бы у одного её участника; нет ни одного — 404.
            $isVariative = $product->is_configurable
                || ($configurableEnabled && $product->parent_id !== null);

            if ($storeOffer === null
                && (! $isVariative || ! self::variativeFamilyHasOffer($product, $storeContextId))
            ) {
                abort(404);
            }
        }

        $outOfStock = $networkEnabled
            ? ($storeOffer !== null && $storeOffer->quantity < $product->minSaleStock())
            : $product->quantity < $product->minSaleStock();

        if ($outOfStock && ! $moduleConfig->isPreordersEnabled() && ! $product->is_configurable && ! $product->isStockIgnored()) {
            abort(404);
        }

        $relations = ['images', 'supplements.values', 'attributes', 'categories'];
        if ($moduleConfig->isBrandsEnabled()) {
            $relations[] = 'brand';
        }
        $product->load($relations);
        $product->loadCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)]);
        self::attachSupplementOffers($product, $storeContextId);

        // Если это child — отдать parent с предвыбранным вариантом
        if ($configurableEnabled && $product->parent_id) {
            $parent = Product::query()
                ->active()
                ->find($product->parent_id);

            if ($parent) {
                $parent->load([
                    'images',
                    'supplements.values',
                    'attributes',
                    'categories',
                    'configurableAttributes',
                    // Офферы вариаций: цена/остаток варианта в сетевом режиме
                    // живут в store_products, а не в колонках карточки-ребёнка.
                    'children' => fn ($q) => $q->active()->with(array_merge([
                        'images',
                        'attributes',
                        'activePromotions',
                    ], $variantOfferLoad)),
                ]);
                $parent->loadCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)]);
                $parent->load($variantOfferLoad);
                self::attachSupplementOffers($parent, $storeContextId);

                if ($moduleConfig->isBrandsEnabled()) {
                    $parent->load('brand');
                }

                $this->applyDeliveryDates($city, $parent);

                return (new ProductResource($parent))->withSelectedVariant((int) $product->id);
            }
        }

        // Если это configurable parent — загрузить children
        if ($configurableEnabled && $product->is_configurable) {
            $product->load([
                'configurableAttributes',
                'children' => fn ($q) => $q->active()->with(array_merge([
                    'images',
                    'attributes',
                    'activePromotions',
                ], $variantOfferLoad)),
            ]);
        }

        $this->applyDeliveryDates($city, $product);

        return new ProductResource($product);
    }

    /**
     * Есть ли в магазине валидный оффер хотя бы у одного участника
     * вариативной семьи (родитель или любая его вариация).
     *
     * Семья резолвится от корня: запрошенной может быть и вариация —
     * карточка всё равно отдаётся родительская.
     *
     * Вариации берём только активные: карточка показывает и цены считает
     * тоже по активным (ProductResource::buildVariants), поэтому оффер
     * на выключенной вариации доступности семье не даёт.
     */
    private static function variativeFamilyHasOffer(Product $product, int $storeId): bool
    {
        $rootId = (int) ($product->parent_id ?? $product->id);

        return StoreProduct::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->where(function ($q) use ($rootId) {
                $q->where('product_id', $rootId)
                    ->orWhereIn('product_id', Product::query()
                        ->select('id')
                        ->where('parent_id', $rootId)
                        ->where('is_active', true));
            })
            ->exists();
    }

    /**
     * Документы товара
     *
     * Возвращает плоский список документов (сертификаты, паспорта, инструкции),
     * отсортированный по `position`; принадлежность к группе указана в поле
     * `group`. При выключенном модуле «Документы товаров» возвращает пустой
     * список со статусом 200.
     *
     * @response array{data: ProductDocumentResource[]}
     */
    public function documents(Product $product): JsonResponse
    {
        if (! app(ModuleConfigService::class)->isDocumentsEnabled()) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => ProductDocumentResource::collection($product->documents),
        ]);
    }

    /**
     * Похожие товары
     *
     * Возвращает покупаемые товары, связанные хотя бы с одной из категорий
     * исходного товара, исключая его самого. В обычном режиме выборка
     * ограничена магазином исходного товара. В сетевом каталоге магазин
     * задаёт контекст (`storeId` либо активный адрес авторизованного
     * пользователя); без контекста выборка не ограничена магазином, цена
     * подставляется из лучшего оффера сети. Порядок случайный.
     *
     * Deprecated snake_case-варианты `only_discounted`, `sugar_free`
     * принимаются как алиасы одноимённых camelCase-параметров.
     *
     * @response array{hasMore: bool, data: ProductResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 50)', type: 'int', default: 10, example: 10)]
    #[QueryParameter('onlyDiscounted', description: 'Только товары со скидкой', type: 'boolean', required: false, example: true)]
    #[QueryParameter('sugarFree', description: 'Только товары без сахара', type: 'boolean', required: false, example: true)]
    #[QueryParameter('cityId', description: 'ID города для расчёта сроков доставки/самовывоза', type: 'int', required: false, example: 1)]
    #[QueryParameter('storeId', description: 'ID магазина: ограничивает выдачу его товарами и задаёт источник цены/остатка', type: 'int', required: false, example: 1)]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function similar(Request $request, Product $product): JsonResponse
    {
        // Backward-compat: принимаем deprecated snake_case query-параметры как алиасы camelCase.
        self::aliasSnakeQueryParams($request, [
            'only_discounted' => 'onlyDiscounted',
            'sugar_free' => 'sugarFree',
        ]);

        $perPage = min($request->integer('perPage', 10), 50);

        $product->loadMissing('categories');

        // Контекст магазина резолвится только в сетевом режиме (при выключенном
        // флаге ручка обязана вести себя как раньше). С ним выдача ограничена
        // товарами этого магазина — заодно закрыт баг «похожие отдают товары
        // чужих магазинов».
        $storeContextId = $this->resolveStoreContextId($request);
        $networkStoreId = $storeContextId > 0 ? $storeContextId : null;

        // Легаси: похожие всегда из магазина исходного товара (фикс main).
        // Сетевой каталог: карточки живут в якоре, forStore(store_id) исходного
        // товара дал бы пустую выдачу — магазин задаётся контекстом ниже.
        $networkEnabled = app(ModuleConfigService::class)->isNetworkCatalogEnabled();

        $query = Product::query()
            ->when(! $networkEnabled, fn ($q) => $q->forStore($product->store_id))
            ->whereHas('categories', function ($q) use ($product) {
                $q->whereIn('categories.id', $product->categories->pluck('id'));
            })
            ->where('products.id', '!=', $product->id)
            ->when(
                $storeContextId > 0,
                fn ($q) => $q->purchasableInStore($storeContextId),
                fn ($q) => $q->purchasable(),
            )
            ->adultVisible(app(AdultContentPolicy::class)->showAdult($request))
            ->with('images')
            // Без контекста магазина в сетевом режиме подставляем лучший оффер
            // сети: карточка не должна уходить клиенту с нулевой ценой.
            ->with(self::storeOfferEagerLoad($storeContextId))
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)]);

        $this->filterQuery->applyToggles(
            $query,
            $request->boolean('onlyDiscounted'),
            $request->boolean('sugarFree'),
            $networkStoreId,
        );

        $products = $query->inRandomOrder()->paginate($perPage);

        $this->applyDeliveryDates($this->resolveCity($request), $products->getCollection());

        return response()->json([
            'hasMore' => $products->hasMorePages(),
            'data' => ProductResource::collection($products),
            'total' => $products->total(),
        ]);
    }

    /**
     * Сопутствующие товары (по соседним категориям)
     *
     * Возвращает товары из sibling-категорий основной категории товара
     * (общий родитель), исключая её саму: до 10 категорий, видимых в магазине
     * товара, и до `productsLimit` товаров на каждую. Категории без покупаемых
     * товаров в ответ не попадают.
     *
     * Магазин выдачи — `storeId` (либо активный адрес авторизованного
     * пользователя), без контекста — магазин самого товара; в сетевом режиме
     * без контекста выдача не ограничена магазином (цена — лучший оффер сети).
     *
     * Пустой массив `data` отдаётся, если у товара нет категории либо
     * у его основной категории нет родителя.
     *
     * @response array{data: array<array{id: int, name: string, slug: string, products: ProductResource[]}>}
     */
    #[QueryParameter('productsLimit', description: 'Максимум товаров на категорию (max: 9)', type: 'int', default: 9)]
    #[QueryParameter('cityId', description: 'ID города для расчёта сроков доставки/самовывоза', type: 'int', required: false, example: 1)]
    #[QueryParameter('storeId', description: 'ID магазина: ограничивает выдачу его товарами и задаёт источник цены/остатка', type: 'int', required: false, example: 1)]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function related(Request $request, Product $product): JsonResponse
    {
        $productsLimit = min($request->integer('productsLimit', 9), 9);

        $category = $product->category;

        if (! $category || ! $category->parent_id) {
            return response()->json(['data' => []]);
        }

        $storeContextId = $this->resolveStoreContextId($request);

        // Легаси (флаг выключен — контекст всегда 0): прежнее поведение,
        // магазин карточки. В сетевом режиме карточка принадлежит якорному
        // магазину, у которого офферов нет, — падение в него отдало бы пустой
        // data, поэтому без контекста выдача идёт по офферам любого магазина.
        if ($storeContextId <= 0 && ! app(ModuleConfigService::class)->isNetworkCatalogEnabled()) {
            $storeContextId = (int) $product->store_id;
        }

        $siblings = Category::query()
            ->where('parent_id', $category->parent_id)
            ->where('id', '!=', $category->id)
            ->active()
            ->when($storeContextId > 0, fn ($q) => $q->forStore($storeContextId))
            ->ordered()
            ->limit(10)
            ->get();

        if ($siblings->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $result = [];
        $allProducts = collect();
        $showAdult = app(AdultContentPolicy::class)->showAdult($request);

        foreach ($siblings as $sibling) {
            $products = Product::query()
                ->when(
                    $storeContextId > 0,
                    fn ($q) => $q->purchasableInStore($storeContextId),
                    fn ($q) => $q->purchasable(),
                )
                ->adultVisible($showAdult)
                ->forCategory($sibling->id)
                ->with('images')
                ->with(self::storeOfferEagerLoad($storeContextId))
                ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
                ->ordered()
                ->limit($productsLimit)
                ->get();

            if ($products->isNotEmpty()) {
                $allProducts = $allProducts->concat($products);
                $result[] = [
                    'id' => (int) $sibling->id,
                    'name' => (string) $sibling->name,
                    'slug' => (string) $sibling->slug,
                    'products' => ProductResource::collection($products),
                ];
            }
        }

        // Один проход на всю выдачу: даты резолвятся по магазину, а не по товару.
        $this->applyDeliveryDates($this->resolveCity($request), $allProducts);

        return response()->json(['data' => $result]);
    }

    /**
     * Поиск товара по штрихкоду
     *
     * Возвращает первый покупаемый товар, совпавший со штрихкодом. Обычный
     * штрихкод ищется среди штрихкодов товара (поле `barcodes`). Весовой
     * штрихкод (ровно 13 цифр с префиксом 20–29) содержит код номенклатуры 1С:
     * из него извлекаются 5 цифр после префикса и поиск идёт по `products.code`
     * с учётом ведущих нулей padding-а. Выборка не ограничена магазином.
     *
     * При включённом сетевом каталоге цена и остаток отдаются по магазину из
     * `storeId` (либо по активному адресу пользователя), и товар без оффера
     * этого магазина отдаёт 404 — в нём он не продаётся. Без контекста цена
     * берётся из лучшего оффера сети.
     *
     * Если товар не найден — 404 с сообщением
     * «Товар с указанным штрихкодом не найден».
     *
     * @response array{data: ProductResource}
     */
    #[QueryParameter('cityId', description: 'ID города для расчёта сроков доставки/самовывоза', type: 'int', required: false, example: 1)]
    #[QueryParameter('storeId', description: 'ID магазина для цены/остатка (сетевой каталог); по умолчанию — магазин активного адреса', type: 'int', required: false, example: 1)]
    public function searchByBarcode(Request $request, string $barcode): JsonResponse
    {
        $moduleConfig = app(ModuleConfigService::class);
        $configurableEnabled = $moduleConfig->isConfigurableProductsEnabled();
        $storeContextId = $this->resolveStoreContextId($request);

        // Точное совпадение по сохранённому штрихкоду приоритетнее разбора весового
        // (20–29 + код 1С) — см. Product::scopeScannedBarcode.
        $product = Product::query()
            ->scannedBarcode($barcode)
            // Сетевой режим с контекстом: товар должен продаваться именно
            // в этом магазине (сканирование идёт в торговом зале).
            ->when(
                $storeContextId > 0,
                fn ($q) => $q->purchasableInStoreExists($storeContextId),
                fn ($q) => $q->purchasable(),
            )
            ->with(self::storeOfferEagerLoad($storeContextId))
            ->first();

        if (! $product) {
            return response()->json([
                'message' => 'Товар с указанным штрихкодом не найден',
            ], 404);
        }

        $relations = ['images', 'supplements.values', 'attributes', 'categories'];
        if ($moduleConfig->isBrandsEnabled()) {
            $relations[] = 'brand';
        }
        $product->load($relations);
        $product->loadCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)]);
        self::attachSupplementOffers($product, $storeContextId);

        if ($configurableEnabled && $product->is_configurable) {
            $product->load([
                'configurableAttributes',
                'children' => fn ($q) => $q->active()->with(array_merge([
                    'images',
                    'attributes',
                    'activePromotions',
                ], self::storeOfferEagerLoad($storeContextId))),
            ]);
        }

        $this->applyDeliveryDates($this->resolveCity($request), $product);

        return response()->json([
            'data' => new ProductResource($product),
        ]);
    }
}
