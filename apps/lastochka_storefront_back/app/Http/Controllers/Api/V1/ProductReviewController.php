<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Review\CreateProductReviewAction;
use App\Actions\Review\UpdateProductReviewAction;
use App\Exceptions\CannotReviewException;
use App\Exceptions\ReviewAlreadyExistsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Review\CreateReviewRequest;
use App\Http\Requests\Api\V1\Review\UpdateReviewRequest;
use App\Http\Resources\Api\V1\ReviewResource;
use App\Models\Product;
use App\Models\ProductReview;
use App\Services\Review\ReviewService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Reviews', weight: 12)]
final class ProductReviewController extends Controller
{
    public function __construct(
        private ReviewService $reviewService,
    ) {}

    /**
     * Отзывы о товаре
     *
     * Возвращает опубликованные отзывы о товаре с пагинацией.
     * Включает средний рейтинг и распределение оценок.
     *
     * averageRating — поле products.rating с кастом decimal:1, поэтому в JSON это
     * строка вида "4.5" (или null, если отзывов нет); считается по всем отзывам
     * товара, включая неопубликованные. ratingsCount считается только по
     * опубликованным, ключи "5"…"1" присутствуют всегда.
     *
     * @response array{hasMore: bool, data: ReviewResource[], total: int, averageRating: string|null, ratingsCount: array<string, int>}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    public function index(Request $request, Product $product): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 100);

        $reviews = ProductReview::query()
            ->where('product_id', $product->id)
            ->where('is_published', true)
            ->orderByDesc('published_at')
            ->paginate($perPage);

        return response()->json([
            'hasMore' => $reviews->hasMorePages(),
            'data' => ReviewResource::collection($reviews),
            'total' => $reviews->total(),
            'averageRating' => $product->rating,
            'ratingsCount' => $this->reviewService->getProductRatingsCount($product),
        ]);
    }

    /**
     * Мой отзыв о товаре
     *
     * Возвращает отзыв текущего пользователя о товаре (если есть)
     * и флаг возможности оставить отзыв.
     *
     * @response array{review: ReviewResource|null, canReview: bool}
     */
    public function my(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        $review = ProductReview::query()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->first();

        return response()->json([
            'review' => $review ? new ReviewResource($review) : null,
            'canReview' => $this->reviewService->canReviewProduct($user, $product),
        ]);
    }

    /**
     * Создать отзыв о товаре
     *
     * Создаёт новый отзыв о товаре.
     * Требуется наличие покупки этого товара в доставленном заказе.
     */
    #[ScrambleResponse(201, description: 'Отзыв создан (отдаётся плоским объектом без обёртки {data})')]
    #[ScrambleResponse(403, type: 'array{message: string}', description: 'Нельзя оставить отзыв: message = «Вы не покупали этот товар»')]
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, string[]>}', description: 'Отзыв уже существует (message = «Вы уже оставляли отзыв», errors.review) либо ошибка валидации rating/description')]
    public function store(
        CreateReviewRequest $request,
        Product $product,
        CreateProductReviewAction $action,
    ): JsonResponse {
        $user = $request->user();

        // Проверяем, что покупал товар
        if (! $this->reviewService->canReviewProduct($user, $product)) {
            // Проверяем, уже есть отзыв или нет права
            $existingReview = ProductReview::query()
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->exists();

            if ($existingReview) {
                throw new ReviewAlreadyExistsException;
            }

            throw new CannotReviewException('Вы не покупали этот товар');
        }

        try {
            $review = $action->execute(
                $user,
                $product,
                $request->validated('rating'),
                $request->validated('description'),
            );
        } catch (UniqueConstraintViolationException) {
            // Гонка: отзыв появился между проверкой и вставкой
            // (например, пропагация из админки) — не 500 (ALADDIN-11937)
            throw new ReviewAlreadyExistsException;
        }

        return response()->json(new ReviewResource($review), 201);
    }

    /**
     * Обновить отзыв о товаре
     *
     * Обновляет существующий отзыв текущего пользователя о товаре.
     * Флаг is_published при обновлении не изменяется; не переданный
     * description записывается как null.
     */
    #[ScrambleResponse(200, description: 'Отзыв обновлён (отдаётся плоским объектом без обёртки {data})')]
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'Отзыв текущего пользователя о товаре не найден (message = «Отзыв не найден»)')]
    public function update(
        UpdateReviewRequest $request,
        Product $product,
        UpdateProductReviewAction $action,
    ): JsonResponse {
        $user = $request->user();

        $review = ProductReview::query()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $review) {
            abort(404, 'Отзыв не найден');
        }

        // Авторизация не нужна — мы уже получили отзыв по user_id текущего пользователя

        $review = $action->execute(
            $review,
            $request->validated('rating'),
            $request->validated('description'),
        );

        return response()->json(new ReviewResource($review));
    }
}
