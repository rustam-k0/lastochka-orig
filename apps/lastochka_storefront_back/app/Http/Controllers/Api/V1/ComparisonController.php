<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProductHasNoCategoryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Comparison\AddComparisonItemRequest;
use App\Http\Resources\Api\V1\ComparisonDetailsResource;
use App\Http\Resources\Api\V1\ComparisonGroupResource;
use App\Models\Comparison;
use App\Models\Product;
use App\Services\Catalog\StoreOfferContext;
use App\Services\Comparison\ComparisonService;
use App\Services\Pricing\ProductPriceResolver;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group('Comparisons', weight: 18)]
final class ComparisonController extends Controller
{
    public function __construct(
        private readonly ComparisonService $comparisonService,
    ) {}

    /**
     * Список групп сравнения пользователя.
     *
     * Каждая группа = одна категория. Возвращается отсортированно по дате создания (DESC).
     */
    #[ScrambleResponse(200, type: 'array{data: ComparisonGroupResource[]}')]
    public function index(Request $request): JsonResponse
    {
        $comparisons = Comparison::query()
            ->where('user_id', $request->user()->id)
            ->withCount('items')
            ->with(['category' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => ComparisonGroupResource::collection($comparisons),
        ]);
    }

    /**
     * Детали группы сравнения по категории — товары + UNION атрибутов.
     */
    #[ScrambleResponse(200, type: 'array{data: ComparisonDetailsResource}')]
    #[ScrambleResponse(404, description: 'Группа сравнения не найдена')]
    public function show(Request $request, int $categoryId): JsonResponse
    {
        $comparison = Comparison::query()
            ->where('user_id', $request->user()->id)
            ->where('category_id', $categoryId)
            ->with([
                'category' => fn ($q) => $q->withTrashed(),
                'products' => function ($query) use ($request): void {
                    $query
                        ->withTrashed()
                        ->adultVisible(app(\App\Services\Catalog\AdultContentPolicy::class)->showAdult($request))
                        ->with(['images', 'attributes', 'activePromotions']);
                },
            ])
            ->first();

        if ($comparison === null) {
            throw new NotFoundHttpException();
        }

        $this->authorize('view', $comparison);

        // Сетевой каталог: цена товара сравнения — из оффера магазина
        // контекста (`storeId` либо активный адрес), без контекста — лучший
        // оффер сети. Иначе колонки карточки дали бы price=0.
        StoreOfferContext::attach(
            $comparison->products,
            app(StoreOfferContext::class)->resolveStoreId($request),
        );

        // Цены от количества (MID-308): лесенки всех товаров сравнения одним
        // запросом (после навешивания офферов: в сетевом каталоге магазин
        // лесенки = магазин оффера, как и у сметы в EstimateController).
        app(ProductPriceResolver::class)->preloadProducts($comparison->products);

        return response()->json([
            'data' => new ComparisonDetailsResource($comparison),
        ]);
    }

    /**
     * Добавить товар в сравнение. Идемпотентно по (user, product).
     */
    #[ScrambleResponse(201, type: 'array{data: array{categoryId: int, productId: int, count: int}}')]
    #[ScrambleResponse(422, description: 'У товара отсутствует категория')]
    public function addItem(AddComparisonItemRequest $request): JsonResponse
    {
        /** @var Product $product */
        $product = Product::query()->findOrFail($request->productId());

        try {
            $result = $this->comparisonService->addItem($request->user(), $product);
        } catch (ProductHasNoCategoryException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'productId' => [$e->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                'categoryId' => (int) $result['comparison']->category_id,
                'productId' => (int) $product->id,
                'count' => (int) $result['count'],
            ],
        ], 201);
    }

    /**
     * Удалить товар из сравнения по product_id.
     */
    #[ScrambleResponse(204, description: 'Удалено')]
    #[ScrambleResponse(404, description: 'Товар в сравнении не найден')]
    public function removeItem(Request $request, int $productId): Response
    {
        $removed = $this->comparisonService->removeItemByProduct($request->user(), $productId);

        if (! $removed) {
            throw new NotFoundHttpException();
        }

        return response()->noContent();
    }

    /**
     * Очистить группу сравнения по категории.
     */
    #[ScrambleResponse(204, description: 'Группа очищена')]
    #[ScrambleResponse(404, description: 'Группа не найдена')]
    public function clear(Request $request, int $categoryId): Response
    {
        $comparison = Comparison::query()
            ->where('user_id', $request->user()->id)
            ->where('category_id', $categoryId)
            ->first();

        if ($comparison === null) {
            throw new NotFoundHttpException();
        }

        $this->authorize('delete', $comparison);

        $comparison->delete();

        return response()->noContent();
    }
}
