<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\AdultContentPolicy;
use App\Services\Catalog\CatalogFilterCriteria;
use App\Services\Catalog\CatalogFilterFacets;
use App\Services\Catalog\CatalogFilterQuery;
use App\Services\Catalog\StoreOfferContext;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Group('Categories', weight: 3)]
final class CategoryController extends Controller
{
    /** Сколько товаров отдаём на leaf-категорию в дереве includeChildren */
    private const LEAF_PRODUCTS_LIMIT = 8;

    /**
     * Корневые категории магазина
     *
     * Возвращает список корневых категорий, видимых в указанном магазине
     * (категория активна и активна её привязка к магазину). Сортировка —
     * по позиции в магазине (pivot `category_store.position`), затем по названию.
     * В режиме сетевого каталога дерево категорий общее на всю сеть: привязка
     * к магазину не проверяется, порядок берётся из `categories.position`.
     * Опционально загружается дерево потомков.
     *
     * @response array{hasMore: bool, data: CategoryResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    #[QueryParameter('includeChildren', description: 'Загрузить дерево потомков (3 уровня вложенности)', type: 'bool', default: false, example: true)]
    #[QueryParameter('includeProducts', description: 'Добавить до 8 товаров в категории без подкатегорий. Работает только вместе с includeChildren. Без параметра берётся дефолт форка (catalog.category_tree.include_products_default)', type: 'bool', example: true)]
    public function index(Request $request, Store $store): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 100);

        // children_count считаем только по видимым детям (active + привязка к магазину),
        // иначе категория, у которой все дети деактивированы, отдаст isLeaf=false
        // при пустом children и мобилка покажет пустой экран вместо товаров
        $visibleChildrenCount = fn ($q) => $q->forStore($store->id)->active();

        // products_count считаем только по покупаемым товарам ЭТОГО магазина —
        // та же семантика, что и товарная выдача ProductController::byCategory
        // (иначе tagText/tagColor в CategoryResource могли бы показаться из-за
        // товаров чужого магазина или неактивных/недоступных к покупке товаров).
        // В сетевом режиме видимость и остаток берутся из оффера магазина;
        // EXISTS-версия scope'а обязательна — join сломал бы count-подзапрос.
        $purchasableProductsCount = fn ($q) => $q->purchasableInStoreExists($store->id);

        $query = Category::query()
            ->orderedForStore($store->id)
            ->root()
            ->active()
            ->withCount(['children' => $visibleChildrenCount, 'products' => $purchasableProductsCount]);

        if ($request->boolean('includeChildren')) {
            $query->with(['children' => function ($q) use ($store, $visibleChildrenCount, $purchasableProductsCount) {
                $q->forStore($store->id)->active()->ordered()->withCount(['children' => $visibleChildrenCount, 'products' => $purchasableProductsCount])
                    ->with(['children' => function ($q2) use ($store, $visibleChildrenCount, $purchasableProductsCount) {
                        $q2->forStore($store->id)->active()->ordered()->withCount(['children' => $visibleChildrenCount, 'products' => $purchasableProductsCount])
                            ->with(['children' => function ($q3) use ($store, $visibleChildrenCount, $purchasableProductsCount) {
                                $q3->forStore($store->id)->active()->ordered()->withCount(['children' => $visibleChildrenCount, 'products' => $purchasableProductsCount]);
                            }]);
                    }]);
            }]);
        }

        $categories = $query->paginate($perPage);

        // Товары в листьях — по флагу includeProducts и только вместе с деревом.
        //
        // Раньше они подгружались всегда при includeChildren=1 и давали 96 % веса
        // ответа: на проде Ласточки 2 291 КБ из 2 376 КБ, 1 715 товаров в 233 листьях.
        // Её клиент их не парсит — в CategoryDto поля products нет, — то есть весь
        // объём выбрасывался. Замер: 1,311 с и 2 376 КБ против 0,104 с и 85 КБ без
        // них, из которых 1,037 с приходилось на сборку ресурсов и json_encode,
        // а не на базу.
        //
        // Дефолт вынесен в конфиг, потому что поле products читают клиенты не всех
        // форков: мобильное приложение Аладдина рисует его в меню ресторана, и
        // лёгкий ответ по умолчанию оставил бы там пустой экран. Форк, где поле
        // не нужно, ставит CATEGORY_TREE_INCLUDE_PRODUCTS_DEFAULT=false.
        // Явный параметр запроса всегда сильнее дефолта — в обе стороны.
        $includeProducts = $request->has('includeProducts')
            ? $request->boolean('includeProducts')
            : (bool) config('catalog.category_tree.include_products_default');

        if ($request->boolean('includeChildren') && $includeProducts) {
            $this->loadProductsForLeafCategories($categories->getCollection(), $store->id);
        }

        return response()->json([
            'hasMore' => $categories->hasMorePages(),
            'data' => CategoryResource::collection($categories),
            'total' => $categories->total(),
        ]);
    }

    /**
     * Загрузить товары для категорий без подкатегорий (leaf-категорий).
     *
     * Лимит на категорию считается на стороне SQL (window function):
     * выборка всего каталога с обрезкой take() в PHP на 13k+ товаров
     * упиралась в memory_limit (GlitchTip STOREFRONT_BACK-1E).
     */
    private function loadProductsForLeafCategories(Collection $categories, int $storeId): void
    {
        $leafIds = $this->collectLeafCategoryIds($categories);

        if ($leafIds->isEmpty()) {
            return;
        }

        $ranked = Product::query()
            ->join('category_product', 'category_product.product_id', '=', 'products.id')
            ->whereIn('category_product.category_id', $leafIds->all())
            ->purchasableInStoreExists($storeId)
            ->select([
                'category_product.category_id',
                'products.id as product_id',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY category_product.category_id ORDER BY products.title, products.id) as rn'),
            ]);

        $pairs = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', '<=', self::LEAF_PRODUCTS_LIMIT)
            ->orderBy('category_id')
            ->orderBy('rn')
            ->get();

        if ($pairs->isEmpty()) {
            return;
        }

        $products = Product::query()
            ->whereIn('id', $pairs->pluck('product_id')->unique()->all())
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
            ->with(array_merge([
                'store',
                'categories' => fn ($q) => $q->whereIn('categories.id', $leafIds->all()),
            ], StoreOfferContext::eagerLoad($storeId)))
            ->get()
            ->keyBy('id');

        $productsByCategory = $pairs
            ->groupBy('category_id')
            ->map(fn (Collection $rows) => $rows
                ->map(fn ($row) => $products->get($row->product_id))
                ->filter()
                ->values());

        $this->setProductsOnLeafCategories($categories, $productsByCategory);
    }

    private function collectLeafCategoryIds(Collection $categories): Collection
    {
        $ids = collect();

        foreach ($categories as $category) {
            if ($category->relationLoaded('children') && $category->children->isEmpty()) {
                $ids->push($category->id);
            } elseif ($category->relationLoaded('children')) {
                $ids = $ids->merge($this->collectLeafCategoryIds($category->children));
            }
        }

        return $ids;
    }

    private function setProductsOnLeafCategories(Collection $categories, Collection $productsByCategory): void
    {
        foreach ($categories as $category) {
            if ($category->relationLoaded('children') && $category->children->isEmpty()) {
                $category->setRelation('products', $productsByCategory->get($category->id, collect()));
            } elseif ($category->relationLoaded('children')) {
                $this->setProductsOnLeafCategories($category->children, $productsByCategory);
            }
        }
    }

    /**
     * Диапазон цен в категории
     *
     * Возвращает минимальную и максимальную цену по покупаемым товарам магазина
     * во всём поддереве категории (сама категория + вложенные подкатегории).
     * `category=0` — по всем товарам магазина сразу (без фильтра по категории),
     * как и в product.byCategory. Используется для ползунка цены в фильтрах.
     * Пустая выборка даёт нули.
     *
     * @response array{min: int, max: int}
     */
    public function priceRange(Store $store, string $category): JsonResponse
    {
        $categoryId = (int) $category;
        $categoryModel = $categoryId > 0 ? Category::findOrFail($categoryId) : null;

        $query = $this->categoryListingProductQuery($store->id, $categoryModel);

        $priceColumn = $this->listingPriceColumn();
        $min = (int) $query->min($priceColumn);
        $max = (int) $query->max($priceColumn);

        return response()->json([
            'min' => $min,
            'max' => $max,
        ]);
    }

    /**
     * Фильтры категории
     *
     * Возвращает набор фильтров для экрана каталога: диапазон цены и список
     * select-атрибутов (у которых есть справочник значений) по покупаемым
     * товарам магазина во всём поддереве категории. `category=0` — по всем
     * товарам магазина сразу (без фильтра по категории), как и в
     * product.byCategory.
     *
     * Формат ответа:
     * - секция `{code:"price", type:"range", min, max}` — всегда первой;
     * - далее по одной секции на атрибут: `{code, name, type:"checkbox", values:[{id, code, name, count, available}]}`;
     * - в конце — флаг-фасеты динамических чекбоксов товаров (custom + enabled +
     *   filterable из настроек): `{code, name, type:"flag", count, available}`, только
     *   если в листинге есть хотя бы один товар с этим флагом = true.
     *
     * Взаимозависимые фильтры: ручка принимает текущий выбор в тех же
     * параметрах, что и product.byCategory. Список секций и значений от выбора
     * не зависит (недоступные значения не пропадают). `count` значения — сколько
     * товаров останется, если его выбрать: считается с выбором во всех остальных
     * группах, но без выбора в своей (внутри группы значения объединяются по OR).
     * `available` — `count > 0`. `min`/`max` цены так же считаются по всем
     * группам, кроме самой цены, и округляются наружу до целых.
     *
     * `onlyDiscounted`, `sugarFree` и `showAdult` сужают саму выдачу, как
     * в листинге, поэтому влияют и на состав значений.
     *
     * Select-атрибут определяется по признаку `attribute_value_id IS NOT NULL`
     * в `product_attributes` (text/int/float атрибуты справочника значений не имеют
     * и в фильтры не попадают).
     *
     * @response array{data: array<array{code: string, name: string, type: string, min?: int, max?: int, count?: int, available?: bool, values?: array<array{id: int, code: string, name: string, count: int, available: bool}>}>}
     */
    #[QueryParameter('priceMin', description: 'Выбранная минимальная цена', type: 'int', required: false, example: 100)]
    #[QueryParameter('priceMax', description: 'Выбранная максимальная цена', type: 'int', required: false, example: 5000)]
    #[QueryParameter('filter', description: 'Выбранные значения: filter[code]=value_code1,value_code2; для динамических чекбоксов — filter[<флаг>]=1', type: 'array', required: false, example: ['manufacturer' => 'schneider,abb'])]
    #[QueryParameter('onlyDiscounted', description: 'Только товары со скидкой', type: 'boolean', required: false, example: true)]
    #[QueryParameter('sugarFree', description: 'Только товары без сахара', type: 'boolean', required: false, example: true)]
    #[QueryParameter('showAdult', description: 'Гость: учитывать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function filters(Request $request, Store $store, string $category): JsonResponse
    {
        $categoryId = (int) $category;
        $categoryModel = $categoryId > 0 ? Category::findOrFail($categoryId) : null;

        return $this->categoryFilters($request, $store, $categoryModel);
    }

    /**
     * Фильтры категории по slug
     *
     * То же самое, что filters, но категория резолвится по slug вместо id.
     * `slug=-` — специальное значение "все категории" (аналог category=0
     * в filters).
     *
     * @response array{data: array<array{code: string, name: string, type: string, min?: int, max?: int, count?: int, available?: bool, values?: array<array{id: int, code: string, name: string, count: int, available: bool}>}>}
     */
    #[QueryParameter('priceMin', description: 'Выбранная минимальная цена', type: 'int', required: false, example: 100)]
    #[QueryParameter('priceMax', description: 'Выбранная максимальная цена', type: 'int', required: false, example: 5000)]
    #[QueryParameter('filter', description: 'Выбранные значения: filter[code]=value_code1,value_code2; для динамических чекбоксов — filter[<флаг>]=1', type: 'array', required: false, example: ['manufacturer' => 'schneider,abb'])]
    #[QueryParameter('onlyDiscounted', description: 'Только товары со скидкой', type: 'boolean', required: false, example: true)]
    #[QueryParameter('sugarFree', description: 'Только товары без сахара', type: 'boolean', required: false, example: true)]
    #[QueryParameter('showAdult', description: 'Гость: учитывать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function filtersBySlug(Request $request, Store $store, string $slug): JsonResponse
    {
        $categoryModel = $slug === '-'
            ? null
            : Category::query()
                ->attachedToStore($store->id)
                ->where('slug', $slug)
                ->firstOrFail();

        return $this->categoryFilters($request, $store, $categoryModel);
    }

    private function categoryFilters(Request $request, Store $store, ?Category $categoryModel): JsonResponse
    {
        $filterQuery = app(CatalogFilterQuery::class);
        $networkStoreId = app(ModuleConfigService::class)->isNetworkCatalogEnabled() ? $store->id : null;
        $showAdult = app(AdultContentPolicy::class)->showAdult($request);

        // Та же выдача, что у product.byCategory до применения выбора пользователя.
        $scope = fn (): Builder => $filterQuery->applyToggles(
            $this->categoryListingProductQuery($store->id, $categoryModel)->adultVisible($showAdult),
            $request->boolean('onlyDiscounted'),
            $request->boolean('sugarFree'),
            $networkStoreId,
        );

        return response()->json([
            'data' => app(CatalogFilterFacets::class)->sections(
                $scope,
                CatalogFilterCriteria::fromRequest($request),
                $networkStoreId,
            ),
        ]);
    }

    /**
     * Базовый query товаров категории (для родительской — всё поддерево).
     * $category = null — все товары магазина без фильтра по категории
     * (только те, у которых есть хоть одна категория), как и в
     * ProductController::productsByCategory при category=0.
     *
     * Используется и для агрегации фильтров, и для подсчёта price-range —
     * чтобы состав товаров совпадал с ProductController::byCategory.
     */
    private function categoryListingProductQuery(int $storeId, ?Category $category): Builder
    {
        return Product::query()
            ->purchasableInStore($storeId)
            ->when(
                $category,
                fn ($q) => $q->forCategorySubtree($category),
                fn ($q) => $q->whereHas('categories'),
            );
    }

    /**
     * Колонка цены для агрегатов листинга: в сетевом каталоге цена живёт
     * в оффере магазина (store_products присоединён join'ом в
     * purchasableInStore), в легаси — в колонке карточки.
     */
    private function listingPriceColumn(): string
    {
        return app(\App\Services\Settings\ModuleConfigService::class)->isNetworkCatalogEnabled()
            ? 'store_products.price'
            : 'products.price';
    }

    /**
     * Категории на главной мобилки
     *
     * Возвращает видимые в магазине категории любого уровня вложенности,
     * помеченные администратором как «Показывать на главной странице»
     * (`show_on_main = true`). Сортировка — по позиции в магазине
     * (pivot `category_store.position`), затем по названию; в сетевом каталоге —
     * по глобальной `categories.position` без привязки к магазину.
     * Пагинации нет — отдаётся весь список.
     *
     * @response array{data: CategoryResource[]}
     */
    public function home(Store $store): JsonResponse
    {
        $categories = Category::query()
            ->orderedForStore($store->id)
            ->showOnMain()
            ->active()
            ->withCount([
                'children' => fn ($q) => $q->forStore($store->id)->active(),
                'products' => fn ($q) => $q->purchasableInStoreExists($store->id),
            ])
            ->get();

        return response()->json([
            'data' => CategoryResource::collection($categories),
        ]);
    }

    /**
     * Категория по slug вместе с цепочкой предков
     *
     * Резолвит категорию по slug (уникален глобально — см. ModelTools::getUniqueLink(),
     * без фильтра по shop_id) и возвращает вместе с ней `ancestors` — путь от
     * корня до родителя категории (сама категория в него не входит). Витрина
     * строит по этому полную цепочку хлебных крошек и ссылку на категорию
     * одним сегментом `/catalog/{slug}`, без похода по дереву от корня.
     *
     * @response array{data: array{id: int, name: string, slug: string, ancestors: array<array{id: int, name: string, slug: string}>}}
     */
    public function showBySlug(Store $store, string $slug): JsonResponse
    {
        $category = Category::whereHas('stores', fn ($q) => $q->where('stores.id', $store->id))
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'ancestors' => $this->buildAncestors($category),
            ],
        ]);
    }

    /**
     * @return list<array{id: int, name: string, slug: string}>
     */
    private function buildAncestors(Category $category): array
    {
        $all = Category::query()->select(['id', 'parent_id', 'name', 'slug'])->get()->keyBy('id');

        $chain = [];
        $current = $category->parent_id ? $all->get($category->parent_id) : null;
        while ($current) {
            array_unshift($chain, ['id' => (int) $current->id, 'name' => (string) $current->name, 'slug' => (string) $current->slug]);
            $current = $current->parent_id ? $all->get($current->parent_id) : null;
        }

        return $chain;
    }

    /**
     * Дочерние категории
     *
     * Возвращает дочерние категории указанной родительской категории, видимые
     * в указанном магазине. Сортировка — по позиции в магазине
     * (pivot `category_store.position`), затем по названию; в сетевом каталоге —
     * по глобальной `categories.position` без привязки к магазину. Связь
     * `children` не загружается — в элементах ответа она равна `null`.
     *
     * @response array{hasMore: bool, data: CategoryResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    public function children(Request $request, Store $store, Category $category): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 100);

        $children = Category::query()
            ->orderedForStore($store->id)
            ->where('categories.parent_id', $category->id)
            ->active()
            ->withCount([
                'children' => fn ($q) => $q->forStore($store->id)->active(),
                'products' => fn ($q) => $q->purchasableInStoreExists($store->id),
            ])
            ->paginate($perPage);

        return response()->json([
            'hasMore' => $children->hasMorePages(),
            'data' => CategoryResource::collection($children),
            'total' => $children->total(),
        ]);
    }
}
