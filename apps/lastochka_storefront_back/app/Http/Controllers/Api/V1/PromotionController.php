<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PromotionProductResource;
use App\Http\Resources\Api\V1\PromotionResource;
use App\Models\Promotion;
use App\Services\Catalog\StoreOfferContext;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Promotions', weight: 10)]
final class PromotionController extends Controller
{
    /**
     * Список акций
     *
     * Возвращает список активных акций с пагинацией, отсортированный по позиции,
     * затем по дате создания от новых к старым. Можно отфильтровать по магазину.
     * Каждая акция содержит магазин (`id`, `name`, `description`) и `productsCount`
     * по всем привязанным товарам, без фильтров активности и наличия.
     * Акции магазинов с ограниченным доступом не отдаются пользователям
     * без соответствующего признака и гостям.
     *
     * @response array{hasMore: bool, data: PromotionResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    #[QueryParameter('storeId', description: 'Фильтр по ID магазина', type: 'int', example: 1)]
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 100);

        $query = Promotion::query()
            ->active()
            ->forUserType(auth('sanctum')->user())
            ->with('store')
            ->withCount('products')
            ->ordered();

        // Фильтр по магазину
        if ($request->has('storeId')) {
            $query->forStore($request->integer('storeId'));
        }

        $promotions = $query->paginate($perPage);

        return response()->json([
            'hasMore' => $promotions->hasMorePages(),
            'data' => PromotionResource::collection($promotions),
            'total' => $promotions->total(),
        ]);
    }

    /**
     * Детали акции
     *
     * Возвращает детальную информацию об акции вместе с магазином
     * (`id`, `name`, `description`). Неактивная акция, акция вне периода
     * показа и акция, скрытая правилами видимости, отдают 404.
     *
     * @response array{data: PromotionResource}
     */
    #[ScrambleResponse(404, description: 'Акция не найдена, неактивна, вне периода показа или скрыта правилами видимости', type: 'array{message: string}')]
    public function show(Promotion $promotion): JsonResponse
    {
        // Проверяем активность акции (дешёвая проверка, без обращения к БД)
        if (! $this->isPromotionActive($promotion)) {
            abort(404, 'Акция не найдена или неактивна');
        }

        // Проверяем видимость акции (B2B-магазин недоступен B2C-пользователю)
        $promotion->loadMissing('store');
        if (! $promotion->isVisibleTo(auth('sanctum')->user())) {
            abort(404, 'Акция не найдена или неактивна');
        }

        $promotion->loadCount('products');

        return response()->json([
            'data' => new PromotionResource($promotion),
        ]);
    }

    /**
     * Товары акции
     *
     * Возвращает список товаров, участвующих в акции, с пагинацией.
     * Только активные товары в наличии и с ценой больше нуля, поэтому `total`
     * может быть меньше, чем `productsCount` акции. Цены `price` и `priceOld`
     * дробные, копейки сохраняются. Неактивная акция, акция вне периода показа
     * и акция, скрытая правилами видимости, отдают 404.
     *
     * @response array{hasMore: bool, data: PromotionProductResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    #[ScrambleResponse(404, description: 'Акция не найдена, неактивна, вне периода показа или скрыта правилами видимости', type: 'array{message: string}')]
    #[QueryParameter('showAdult', description: 'Гость: показать 18+ товары (adult_only). Для авторизованного игнорируется — действует настройка профиля adultConfirmed', type: 'boolean', required: false, example: true)]
    public function products(Request $request, Promotion $promotion): JsonResponse
    {
        // Проверяем активность акции (дешёвая проверка, без обращения к БД)
        if (! $this->isPromotionActive($promotion)) {
            abort(404, 'Акция не найдена или неактивна');
        }

        // Проверяем видимость акции (B2B-магазин недоступен B2C-пользователю)
        $promotion->loadMissing('store');
        if (! $promotion->isVisibleTo(auth('sanctum')->user())) {
            abort(404, 'Акция не найдена или неактивна');
        }

        $perPage = min($request->integer('perPage', 20), 100);

        // Сетевой каталог: наличие и цена товара акции — из оффера магазина
        // САМОЙ акции (promotions.store_id); колонки карточки там нулевые.
        $networkStoreId = app(ModuleConfigService::class)->isNetworkCatalogEnabled()
            ? (int) $promotion->store_id
            : 0;

        $products = $promotion->products()
            ->active()
            ->adultVisible(app(\App\Services\Catalog\AdultContentPolicy::class)->showAdult($request))
            ->when(
                $networkStoreId > 0,
                fn ($q) => $q->offeredInStore($networkStoreId),
                fn ($q) => $q->inStock()->hasPrice(),
            )
            ->with(['images', 'favorites'])
            ->with(StoreOfferContext::eagerLoad($networkStoreId))
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
            // Без ORDER BY MySQL не гарантирует порядок строк между
            // страницами — как и в каталоге, сортируем по названию + id.
            ->ordered()
            ->paginate($perPage);

        return response()->json([
            'hasMore' => $products->hasMorePages(),
            'data' => PromotionProductResource::collectionWithPromotion(
                $products->getCollection(),
                $promotion,
                $request->user()
            ),
            'total' => $products->total(),
        ]);
    }

    /**
     * Проверить активность акции (is_active=true и текущая дата в диапазоне).
     */
    private function isPromotionActive(Promotion $promotion): bool
    {
        return $promotion->is_active
            && $promotion->starts_at <= now()
            && $promotion->ends_at >= now();
    }
}
