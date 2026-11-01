<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Favorite\ToggleFavoriteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Favorite\RemoveFavoritesRequest;
use App\Http\Requests\Api\V1\Favorite\ToggleFavoriteRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Favorite;
use App\Models\Product;
use App\Services\Catalog\StoreOfferContext;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Favorites', weight: 11)]
final class FavoriteController extends Controller
{
    /**
     * Список избранных товаров
     *
     * Возвращает товары, добавленные в избранное текущим пользователем,
     * отсортированные по дате добавления в избранное. Направление задаётся
     * параметром sort: date_added_desc — новые сверху (по умолчанию),
     * date_added_asc — старые сверху.
     * Товары, не прошедшие scope purchasable() (неактивные, с нулевой ценой,
     * варианты конфигурируемых товаров), и удалённые исключаются из data,
     * при этом total считает все записи избранного, включая исключённые.
     *
     * При включённом сетевом каталоге видимость, цена и остаток берутся по
     * магазину из `storeId` (либо по активному адресу пользователя): товар без
     * оффера этого магазина в избранном не показывается. Без контекста цена —
     * лучший оффер сети.
     *
     * @response array{hasMore: bool, data: ProductResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    #[QueryParameter('sort', description: 'Сортировка по дате добавления в избранное: date_added_desc — новые сверху, date_added_asc — старые сверху. Без параметра (и при нераспознанном значении) — date_added_desc', type: 'string', required: false, example: 'date_added_asc')]
    #[QueryParameter('storeId', description: 'ID магазина для цены/остатка (сетевой каталог); по умолчанию — магазин активного адреса', type: 'int', required: false, example: 1)]
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 100);
        $storeContextId = app(StoreOfferContext::class)->resolveStoreId($request);

        $favorites = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->tap(fn ($query) => match ($request->string('sort')->toString()) {
                // id как tie-breaker: created_at добавленных подряд товаров
                // совпадает с точностью до секунды, без него порядок страниц плавает
                'date_added_asc' => $query->orderBy('created_at')->orderBy('id'),
                default => $query->orderByDesc('created_at')->orderByDesc('id'),
            })
            ->with(['product' => function ($query) use ($storeContextId, $request) {
                $query
                    ->when(
                        $storeContextId > 0,
                        fn ($q) => $q->purchasableInStoreExists($storeContextId),
                        fn ($q) => $q->purchasable(),
                    )
                    ->adultVisible(app(\App\Services\Catalog\AdultContentPolicy::class)->showAdult($request))
                    ->with('images')
                    ->with(StoreOfferContext::eagerLoad($storeContextId))
                    ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)]);
            }])
            ->paginate($perPage);

        // Отфильтровать удалённые/неактивные товары
        $products = $favorites->getCollection()
            ->filter(fn (Favorite $favorite) => $favorite->product !== null)
            ->map(fn (Favorite $favorite) => $favorite->product);

        return response()->json([
            'hasMore' => $favorites->hasMorePages(),
            'data' => ProductResource::collection($products),
            'total' => $favorites->total(),
        ]);
    }

    /**
     * Переключить избранное
     *
     * Добавляет товар в избранное, если его там нет, или удаляет, если есть.
     * Активность товара при добавлении не проверяется.
     * Возвращает товар с обновлённым статусом isFavorite.
     *
     * @response array{data: ProductResource}
     */
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'Товар удалён (правило exists не учитывает soft delete, загрузка товара даёт 404)')]
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, string[]>}', description: 'Ошибка валидации productId либо достигнут лимит избранного: message = «Достигнут лимит избранного (максимум 500 товаров).», errors.productId')]
    public function toggle(
        ToggleFavoriteRequest $request,
        ToggleFavoriteAction $action,
    ): JsonResponse {
        $product = Product::with('images')
            // Ответ содержит карточку товара — цену/остаток отдаём по магазину
            // контекста (сетевой каталог), иначе они были бы нулевыми.
            ->with(StoreOfferContext::eagerLoad(app(StoreOfferContext::class)->resolveStoreId($request)))
            ->findOrFail($request->validated('productId'));

        $product = $action->execute($request->user(), $product);

        return response()->json([
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Массовое удаление из избранного
     *
     * Удаляет из избранного текущего пользователя товары с переданными ID.
     * ID, которых нет в избранном (в том числе несуществующих товаров),
     * молча игнорируются — операция идемпотентна. Существование товаров
     * не проверяется: запись избранного могла пережить удаление товара.
     * Возвращает количество реально удалённых записей.
     *
     * @response array{removed: int}
     */
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, string[]>}', description: 'Ошибка валидации productIds: не передан, пустой, не массив, больше 500 элементов или содержит нечисловые ID')]
    public function destroy(RemoveFavoritesRequest $request): JsonResponse
    {
        $removed = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('product_id', $request->validated('productIds'))
            ->delete();

        return response()->json(['removed' => $removed]);
    }
}
