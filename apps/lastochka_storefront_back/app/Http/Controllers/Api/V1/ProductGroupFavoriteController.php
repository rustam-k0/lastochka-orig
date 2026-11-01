<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductGroupResource;
use App\Models\ProductGroup;
use App\Models\ProductGroupFavorite;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Favorites', weight: 11)]
final class ProductGroupFavoriteController extends Controller
{
    /**
     * Список избранных решений
     *
     * Возвращает комплексные решения, добавленные в избранное текущим
     * пользователем, отсортированные по дате добавления. Направление задаётся
     * параметром sort: date_added_desc — новые сверху (по умолчанию),
     * date_added_asc — старые сверху.
     * Неактивные (is_active = false) и удалённые решения исключаются из data,
     * при этом total считает все записи избранного, включая исключённые.
     *
     * @response array{hasMore: bool, data: ProductGroupResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1)]
    #[QueryParameter('perPage', description: 'Количество на странице (max: 100)', type: 'int', default: 20)]
    #[QueryParameter('sort', description: 'Сортировка по дате добавления в избранное: date_added_desc — новые сверху, date_added_asc — старые сверху. Без параметра (и при нераспознанном значении) — date_added_desc', type: 'string', required: false, example: 'date_added_asc')]
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 100);

        $favorites = ProductGroupFavorite::query()
            ->where('user_id', $request->user()->id)
            ->tap(fn ($query) => match ($request->string('sort')->toString()) {
                // id как tie-breaker: created_at добавленных подряд решений
                // совпадает с точностью до секунды, без него порядок страниц плавает
                'date_added_asc' => $query->orderBy('created_at')->orderBy('id'),
                default => $query->orderByDesc('created_at')->orderByDesc('id'),
            })
            ->with(['productGroup' => function ($query) {
                $query->where('is_active', true)->withCount('products');
            }])
            ->paginate($perPage);

        $groups = $favorites->getCollection()
            ->filter(fn (ProductGroupFavorite $fav) => $fav->productGroup !== null)
            ->map(fn (ProductGroupFavorite $fav) => $fav->productGroup);

        return response()->json([
            'hasMore' => $favorites->hasMorePages(),
            'data' => ProductGroupResource::collection($groups),
            'total' => $favorites->total(),
        ]);
    }

    /**
     * Переключить избранное для решения
     *
     * Добавляет решение в избранное или удаляет, если уже есть.
     * Ответ отдаётся без обёртки {data}, сама группа в него не входит.
     * Активность решения при добавлении не проверяется, лимит не установлен.
     *
     * @response array{isFavorite: bool}
     */
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'productGroupId' => ['required', 'integer', 'exists:product_groups,id'],
        ], [
            'productGroupId.required' => 'Укажите решение',
            'productGroupId.exists' => 'Решение не найдено',
        ]);

        $userId = $request->user()->id;
        $productGroupId = $request->integer('productGroupId');

        $existing = ProductGroupFavorite::query()
            ->where('user_id', $userId)
            ->where('product_group_id', $productGroupId)
            ->first();

        if ($existing) {
            $existing->delete();
            $isFavorite = false;
        } else {
            // createOrFirst: параллельный toggle (двойной тап) мог вставить ту же
            // строку после проверки выше — тогда UNIQUE не роняет запрос в 500.
            ProductGroupFavorite::query()->createOrFirst([
                'user_id' => $userId,
                'product_group_id' => $productGroupId,
            ]);
            $isFavorite = true;
        }

        return response()->json(['isFavorite' => $isFavorite]);
    }

    /**
     * Массовое удаление решений из избранного
     *
     * Удаляет из избранного текущего пользователя решения с переданными ID.
     * ID, которых нет в избранном (в том числе несуществующих решений),
     * молча игнорируются — операция идемпотентна.
     * Возвращает количество реально удалённых записей.
     *
     * @response array{removed: int}
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'productGroupIds' => ['required', 'array', 'min:1', 'max:500'],
            'productGroupIds.*' => ['integer'],
        ], [
            'productGroupIds.required' => 'Укажите решения',
            'productGroupIds.array' => 'Укажите решения списком',
            'productGroupIds.min' => 'Укажите хотя бы одно решение',
            'productGroupIds.max' => 'Не больше 500 решений за раз',
            'productGroupIds.*.integer' => 'ID решения должен быть числом',
        ]);

        $removed = ProductGroupFavorite::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('product_group_id', $request->input('productGroupIds'))
            ->delete();

        return response()->json(['removed' => $removed]);
    }
}
