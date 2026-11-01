<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Review\CreateOrderReviewAction;
use App\Actions\Review\UpdateOrderReviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderReviewRequest;
use App\Http\Requests\UpdateOrderReviewRequest;
use App\Http\Resources\OrderReviewResource;
use App\Models\Order;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Reviews', weight: 12)]
final class OrderReviewController extends Controller
{
    public function __construct(
        private CreateOrderReviewAction $createOrderReviewAction,
        private UpdateOrderReviewAction $updateOrderReviewAction,
    ) {}

    /**
     * Отзыв на заказ
     *
     * Возвращает отзыв текущего пользователя на заказ. Владение самим заказом
     * отдельно не проверяется: чужой отзыв отсекается фильтром по user_id.
     * Поля ресурса — в snake_case.
     *
     * @response array{data: OrderReviewResource}
     */
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'Отзыв текущего пользователя на этот заказ не найден (message = «Отзыв не найден»)')]
    public function show(Request $request, Order $order): JsonResponse
    {
        $review = $order->review()
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$review) {
            return response()->json(['message' => 'Отзыв не найден'], 404);
        }

        return response()->json(['data' => new OrderReviewResource($review)]);
    }

    /**
     * Создать отзыв на заказ
     *
     * Создаёт отзыв на заказ текущего пользователя. Требуется, чтобы заказ
     * принадлежал пользователю и имел статус delivered. Ограничение частоты —
     * 10 запросов в минуту. Максимальная длина description — 1000 символов.
     * Рейтинги магазина и товара по отзывам на заказ не пересчитываются.
     */
    #[ScrambleResponse(201, type: 'array{data: OrderReviewResource}', description: 'Отзыв создан')]
    #[ScrambleResponse(403, type: 'array{message: string}', description: 'Заказ принадлежит другому пользователю или не в статусе delivered (стандартный отказ Laravel)')]
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, string[]>}', description: 'Отзыв на заказ уже существует (message = «На этот заказ уже оставлен отзыв», errors.review) либо ошибка валидации rating/description')]
    #[ScrambleResponse(429, type: 'array{message: string}', description: 'Превышено ограничение частоты (10 запросов в минуту)')]
    public function store(CreateOrderReviewRequest $request, Order $order): JsonResponse
    {
        $review = $this->createOrderReviewAction->execute(
            user: $request->user(),
            order: $order,
            rating: $request->validated('rating'),
            description: $request->validated('description')
        );

        return response()->json(
            ['data' => new OrderReviewResource($review)],
            201
        );
    }

    /**
     * Обновить отзыв на заказ
     *
     * Обновляет отзыв на заказ. Ограничение частоты — 10 запросов в минуту.
     * Максимальная длина description — 1000 символов, не переданный description
     * записывается как null.
     *
     * @response array{data: OrderReviewResource}
     */
    #[ScrambleResponse(403, type: 'array{message: string}', description: 'Отзыв принадлежит другому пользователю')]
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'У заказа нет отзыва (message = «Отзыв не найден»)')]
    #[ScrambleResponse(429, type: 'array{message: string}', description: 'Превышено ограничение частоты (10 запросов в минуту)')]
    public function update(UpdateOrderReviewRequest $request, Order $order): JsonResponse
    {
        $review = $order->review;

        if (!$review) {
            return response()->json(['message' => 'Отзыв не найден'], 404);
        }

        $updatedReview = $this->updateOrderReviewAction->execute(
            user: $request->user(),
            review: $review,
            rating: $request->validated('rating'),
            description: $request->validated('description')
        );

        return response()->json(['data' => new OrderReviewResource($updatedReview)]);
    }
}
