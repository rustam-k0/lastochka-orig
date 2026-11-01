<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductGroup\GetProductGroupsRequest;
use App\Http\Requests\Api\V1\ProductGroup\GetStoreProductGroupsRequest;
use App\Http\Resources\Api\V1\ImageResource;
use App\Http\Resources\Api\V1\ProductGroupResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\ProductGroup;
use App\Models\ProductGroupFilterValue;
use App\Models\Store;
use App\Services\Catalog\AdultContentPolicy;
use App\Services\Catalog\StoreOfferContext;
use App\Services\ProductGroup\ProductGroupProductsResolver;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

#[Group('Product Groups', weight: 4)]
final class ProductGroupController extends Controller
{
    /** Явные сортировки страницы группы ({@see self::applyGroupSorting()}). */
    private const GROUP_SORTS = ['popular', 'price_asc', 'price_desc', 'discount', 'newest'];

    public function __construct(
        private readonly ProductGroupProductsResolver $productsResolver,
    ) {}

    /**
     * Товарные группы для главной, поиска, корзины и фасетного каталога
     *
     * Возвращает активные товарные группы всех магазинов, отсортированные по позиции.
     * Место размещения задаётся параметрами: `placement=home` для главной,
     * `placement=search` для страницы поиска, `isCartPage` для страницы корзины.
     * С параметром `filterValues` выдача ограничивается комплексными решениями,
     * которые имеют все переданные значения фильтров; без него комплексные решения
     * из выдачи исключаются. Каждый элемент содержит магазин и счётчик товаров.
     * Пагинации нет.
     *
     * Под middleware OptionalAuth: при валидном Bearer-токене заполняется `isFavorite`.
     *
     * @response array{data: ProductGroupResource[]}
     */
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function home(GetProductGroupsRequest $request): AnonymousResourceCollection
    {
        $query = ProductGroup::query()
            ->active()
            ->orderBy('position');

        if ($request->placement() === 'home') {
            $query->forHome();
        }

        if ($request->placement() === 'search') {
            $query->forSearch();
        }

        if ($request->isCartPage() !== null) {
            $query->where('is_cart_page', $request->isCartPage());
        }

        if ($request->withProducts()) {
            $showAdult = app(AdultContentPolicy::class)->showAdult($request);
            $query->with(['products' => fn ($q) => $q->adultVisible($showAdult)->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])->limit($request->productsLimit())]);
        }

        $filterValueIds = $request->input('filterValues');
        if ($filterValueIds) {
            // Фасетный каталог — только комплексные решения
            $query->complexSolution();

            $ids = array_map('intval', explode(',', $filterValueIds));
            foreach ($ids as $valueId) {
                $query->whereHas('filterValues', fn ($q) => $q->where('product_group_filter_values.id', $valueId));
            }
        } else {
            // Места размещения — комплексные решения не участвуют
            $query->withoutComplexSolutions();
        }

        $query->with('store');
        $query->withCount('products');

        $groups = $this->applyAutomaticGroups($query->get(), $request, $request->withProducts(), $request->productsLimit());

        $this->attachStoreOffers($groups);

        return ProductGroupResource::collection($groups);
    }

    /**
     * Товарные группы магазина
     *
     * Возвращает активные товарные группы указанного магазина, отсортированные
     * по позиции. Можно фильтровать по размещению (страница магазина или каталог).
     * Магазин в элементах не отдаётся, комплексные решения не исключаются.
     * Пагинации нет.
     *
     * Под middleware OptionalAuth: при валидном Bearer-токене заполняется `isFavorite`.
     *
     * @response array{data: ProductGroupResource[]}
     */
    #[ScrambleResponse(404, description: 'Магазин не найден', type: 'array{message: string}')]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function index(GetStoreProductGroupsRequest $request, Store $store): AnonymousResourceCollection
    {
        $query = $store->productGroups()
            ->active()
            ->orderBy('position');

        // Фильтр по placement
        match ($request->placement()) {
            'store_page' => $query->forStorePage(),
            'catalog' => $query->forCatalog(),
            default => null,
        };

        // Подгрузить товары если запрошено
        if ($request->withProducts()) {
            $showAdult = app(AdultContentPolicy::class)->showAdult($request);
            $query->with(['products' => fn ($q) => $q->adultVisible($showAdult)->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])->limit($request->productsLimit())]);
        }

        $query->withCount('products');

        $groups = $this->applyAutomaticGroups($query->get(), $request, $request->withProducts(), $request->productsLimit());

        $this->attachStoreOffers($groups);

        return ProductGroupResource::collection($groups);
    }

    /**
     * Детали товарной группы
     *
     * Возвращает детальную информацию о товарной группе вместе с магазином
     * и счётчиком товаров. Товары не отдаются. Неактивная группа отдаёт 404.
     * У автоматической группы счётчик считается критерием для пользователя
     * запроса (у личной группы для гостя — 0).
     *
     * Под middleware OptionalAuth: при валидном Bearer-токене заполняется `isFavorite`.
     *
     * @response array{data: ProductGroupResource}
     */
    #[ScrambleResponse(404, description: 'Товарная группа не найдена или неактивна', type: 'array{message: string}')]
    public function show(Request $request, ProductGroup $productGroup): ProductGroupResource
    {
        if (! $productGroup->is_active) {
            abort(404);
        }

        $productGroup->load('store');

        if ($productGroup->fillType()->isAutomatic()) {
            $productGroup->setAttribute('products_count', $this->automaticProductsCount($productGroup, $request));
        } else {
            $productGroup->loadCount('products');
        }

        return new ProductGroupResource($productGroup);
    }

    /**
     * Товары товарной группы
     *
     * Возвращает доступные к покупке товары указанной товарной группы
     * с пагинацией, в порядке позиции внутри группы. Изображения товаров
     * не подгружаются. Неактивная группа отдаёт 404.
     *
     * Автоматическая группа (fillType не manual) собирается критерием в порядке
     * его правил. Личная группа (purchase_history, viewed_not_bought) — под
     * пользователя из Bearer-токена (middleware OptionalAuth); гостю и при
     * товарах меньше порога — 200 с пустым списком.
     *
     * @return array{hasMore: bool, data: ProductResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    #[ScrambleResponse(404, description: 'Товарная группа не найдена или неактивна', type: 'array{message: string}')]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function products(Request $request, ProductGroup $productGroup): array
    {
        if (! $productGroup->is_active) {
            abort(404);
        }

        $perPage = min($request->integer('perPage', 20), 100);

        // Личная группа, которую этому пользователю не отдаём (гость, меньше
        // personal_min_products товаров), — пустой список, не 404.
        if (! $this->productsResolver->isVisibleFor($productGroup, $request->user())) {
            return ['hasMore' => false, 'data' => [], 'total' => 0];
        }

        // manual — связь группы (позиции админа), критерии — запрос резолвера
        // в порядке правил группы.
        $products = $this->productsResolver->query($productGroup, $request->user())
            ->adultVisible(app(AdultContentPolicy::class)->showAdult($request))
            ->with(StoreOfferContext::eagerLoad((int) $productGroup->store_id))
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
            ->paginate($perPage);

        return [
            'hasMore' => $products->hasMorePages(),
            'data' => ProductResource::collection($products),
            'total' => $products->total(),
        ];
    }

    /**
     * Товары товарной группы по slug (страница группы на витрине)
     *
     * Полный аналог ProductController::byCategorySlug для товарных групп —
     * та же сортировка (popular/price_asc/price_desc/discount/newest),
     * диапазон цен, фильтры «со скидкой»/«без сахара». slug уникален в
     * рамках магазина (группа принадлежит ровно одному store_id), поэтому
     * без scope по store здесь можно было бы найти чужую группу с тем же
     * slug из другого магазина.
     *
     * Без sort (и при нераспознанном значении) — порядок, заданный
     * администратором внутри группы (pivot position), как и было в
     * products(). При заданном sort он берёт верх над position.
     *
     * Автоматическая группа (fillType не manual): без sort — порядок правил
     * критерия; личная группа — под пользователя из Bearer-токена
     * (OptionalAuth), гостю и при товарах меньше порога — пустой список.
     *
     * @return array{group: array{id: int, title: string, slug: string|null, description: string|null, image: array}, hasMore: bool, data: ProductResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    #[QueryParameter('sort', description: 'Сортировка: popular, price_asc, price_desc, discount, newest. Без параметра (и при нераспознанном значении) — порядок, заданный администратором внутри группы', type: 'string', required: false, example: 'price_asc')]
    #[QueryParameter('priceMin', description: 'Минимальная цена', type: 'int', required: false, example: 100)]
    #[QueryParameter('priceMax', description: 'Максимальная цена', type: 'int', required: false, example: 1000)]
    #[QueryParameter('onlyDiscounted', description: 'Только товары со скидкой', type: 'boolean', required: false, example: true)]
    #[QueryParameter('sugarFree', description: 'Только товары без сахара', type: 'boolean', required: false, example: true)]
    #[ScrambleResponse(404, description: 'Товарная группа не найдена или неактивна', type: 'array{message: string}')]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function productsBySlug(Request $request, Store $store, string $slug): JsonResponse
    {
        $productGroup = ProductGroup::query()
            ->where('store_id', $store->id)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $perPage = min($request->integer('perPage', 20), 100);

        $groupBlock = [
            'id' => $productGroup->id,
            'title' => $productGroup->title,
            'slug' => $productGroup->slug,
            'description' => $productGroup->description,
            'image' => ImageResource::fromPath($productGroup->image_path, $productGroup->id),
        ];

        // Личная группа, которую этому пользователю не отдаём, — пустой список.
        if (! $this->productsResolver->isVisibleFor($productGroup, $request->user())) {
            return response()->json([
                'group' => $groupBlock,
                'hasMore' => false,
                'data' => [],
                'total' => 0,
            ]);
        }

        $automatic = $productGroup->fillType()->isAutomatic();
        $sort = $request->string('sort')->toString();

        // manual: порядок задаёт applyGroupSorting (по умолчанию — позиция
        // админа). Критерий: по умолчанию порядок правил группы из резолвера,
        // явная сортировка применяется поверх него через reorder().
        $query = $this->productsResolver->query($productGroup, $request->user());

        if (! $automatic || in_array($sort, self::GROUP_SORTS, true)) {
            $query->reorder();
        }

        $query->adultVisible(app(AdultContentPolicy::class)->showAdult($request));

        // Сетевой каталог: фильтры и сортировки по цене считаются по офферу
        // магазина группы (колонки products.price/price_old там нулевые),
        // поэтому оффер присоединяем join'ом.
        $network = app(ModuleConfigService::class)->isNetworkCatalogEnabled();

        if ($network) {
            $query->joinStoreOffer((int) $productGroup->store_id);
        }

        $priceColumn = $network ? 'store_products.price' : 'price';
        $priceOldColumn = $network ? 'store_products.price_old' : 'price_old';

        if ($request->boolean('onlyDiscounted')) {
            $query->where(fn ($q) => $q->whereNotNull($priceOldColumn)->orWhereHas('activePromotions'));
        }
        if ($request->boolean('sugarFree')) {
            $query->sugarFree();
        }
        if ($request->filled('priceMin')) {
            $query->where($priceColumn, '>=', $request->integer('priceMin'));
        }
        if ($request->filled('priceMax')) {
            $query->where($priceColumn, '<=', $request->integer('priceMax'));
        }

        if (! $automatic || in_array($sort, self::GROUP_SORTS, true)) {
            $this->applyGroupSorting(
                $query,
                $sort,
                $priceColumn,
                $priceOldColumn,
                // с join'ом оффера created_at есть в обеих таблицах — квалифицируем
                $network ? 'products.created_at' : 'created_at',
            );
        }

        $products = $query
            ->with(StoreOfferContext::eagerLoad((int) $productGroup->store_id))
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
            ->paginate($perPage);

        return response()->json([
            'group' => $groupBlock,
            'hasMore' => $products->hasMorePages(),
            'data' => ProductResource::collection($products),
            'total' => $products->total(),
        ]);
    }

    /**
     * Сортировка товаров внутри группы. Семантика идентична
     * ProductController::applySorting() для каталога категорий, но fallback —
     * не «по названию», а по позиции в группе (pivot), заданной администратором.
     *
     * Колонки цены передаются извне: в сетевом режиме это колонки оффера
     * магазина группы, в легаси — колонки карточки.
     */
    private function applyGroupSorting(
        $query,
        string $sort,
        string $price = 'price',
        string $priceOld = 'price_old',
        string $createdAt = 'created_at',
    ): void {
        // Tie-break в каждой ветке — как в ProductController::applySorting():
        // без него равные по ключу строки при LIMIT/OFFSET перемешиваются
        // между страницами (дубли и пропуски товаров).
        match ($sort) {
            'popular' => $query->withCount('orderItems')->orderByDesc('order_items_count')->ordered(),
            'price_asc' => $query->orderBy($price)->ordered(),
            'price_desc' => $query->orderByDesc($price)->ordered(),
            'discount' => $query
                ->orderByRaw("CASE WHEN {$priceOld} IS NOT NULL AND {$priceOld} > 0 THEN ({$priceOld} - {$price}) * 100 / {$priceOld} ELSE 0 END DESC")
                ->ordered(),
            'newest' => $query->orderByDesc($createdAt)->orderByDesc('products.id'),
            default => $query->orderByPivot('position')->orderBy('products.id'),
        };
    }

    /**
     * Автоматические группы (fill_type не manual) в списках групп: товары
     * и счётчик — через резолвер, а не через product_group_items (их у таких
     * групп нет). Личные группы, которые пользователю не отдаём (гость, меньше
     * personal_min_products товаров), из выдачи исключаются. Ручные группы
     * не трогаем — у них товары/счётчик уже загружены запросом списка.
     *
     * @param  Collection<int, ProductGroup>  $groups
     * @return Collection<int, ProductGroup>
     */
    private function applyAutomaticGroups(Collection $groups, Request $request, bool $withProducts, int $productsLimit): Collection
    {
        $user = $request->user();
        $showAdult = app(AdultContentPolicy::class)->showAdult($request);

        return $groups->filter(function (ProductGroup $group) use ($user, $showAdult, $withProducts, $productsLimit): bool {
            if (! $group->fillType()->isAutomatic()) {
                return true;
            }

            if (! $this->productsResolver->isVisibleFor($group, $user)) {
                return false;
            }

            $query = $this->productsResolver->query($group, $user);

            if ($query === null) {
                return false;
            }

            $query->adultVisible($showAdult);

            $group->setAttribute('products_count', (clone $query)->reorder()->count());

            if ($withProducts) {
                $group->setRelation('products', $query
                    ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
                    ->limit($productsLimit)
                    ->get());
            }

            return true;
        })->values();
    }

    /**
     * Счётчик товаров автоматической группы для пользователя запроса
     * (0 — личная группа, которую ему не отдаём).
     */
    private function automaticProductsCount(ProductGroup $group, Request $request): int
    {
        if (! $this->productsResolver->isVisibleFor($group, $request->user())) {
            return 0;
        }

        $query = $this->productsResolver->query($group, $request->user());

        if ($query === null) {
            return 0;
        }

        return $query
            ->adultVisible(app(AdultContentPolicy::class)->showAdult($request))
            ->reorder()
            ->count();
    }

    /**
     * Подставить загруженным товарам групп офферы магазина ИХ группы.
     *
     * Один constrained eager load тут не подходит: в выдаче бывают группы
     * разных магазинов (главная агрегирует все), а цена/остаток товара
     * в сетевом режиме зависят от магазина.
     *
     * @param  \Illuminate\Support\Collection<int, ProductGroup>  $groups
     */
    private function attachStoreOffers($groups): void
    {
        foreach ($groups as $group) {
            if (! $group->relationLoaded('products')) {
                continue;
            }

            StoreOfferContext::attach($group->products, (int) $group->store_id);
        }
    }

    /**
     * Похожие решения (siblings)
     *
     * Возвращает другие активные комплексные решения, у которых совпадают все
     * значения нестилевых фильтров исходной группы (например Комната и Площадь).
     * Значения фильтра «Стиль» в подборе не участвуют, сама группа исключается.
     * Если у группы нет нестилевых значений фильтров, список пустой.
     * Неактивная группа отдаёт 404.
     *
     * @response array{data: ProductGroupResource[]}
     */
    #[ScrambleResponse(404, description: 'Товарная группа не найдена или неактивна', type: 'array{message: string}')]
    public function siblings(ProductGroup $productGroup): AnonymousResourceCollection
    {
        if (! $productGroup->is_active) {
            abort(404);
        }

        // Получаем filter values текущей группы, сгруппированные по фильтру
        $currentValues = $productGroup->filterValues()
            ->with('filter')
            ->get()
            ->groupBy(fn (ProductGroupFilterValue $v) => $v->filter->name);

        // Собираем ID значений НЕ-стилевых фильтров (Комната, Площадь)
        $matchValueIds = [];
        $styleValueIds = [];

        foreach ($currentValues as $filterName => $values) {
            $ids = $values->pluck('id')->toArray();

            if (mb_strtolower($filterName) === 'стиль') {
                $styleValueIds = $ids;
            } else {
                $matchValueIds = array_merge($matchValueIds, $ids);
            }
        }

        if (empty($matchValueIds)) {
            return ProductGroupResource::collection(collect());
        }

        // Ищем группы, которые имеют ВСЕ те же non-style значения
        $query = ProductGroup::query()
            ->active()
            ->complexSolution()
            ->where('id', '!=', $productGroup->id)
            ->withCount('products');

        foreach ($matchValueIds as $valueId) {
            $query->whereHas('filterValues', fn ($q) => $q->where('product_group_filter_values.id', $valueId));
        }

        $siblings = $query->orderBy('position')->get();

        return ProductGroupResource::collection($siblings);
    }
}
