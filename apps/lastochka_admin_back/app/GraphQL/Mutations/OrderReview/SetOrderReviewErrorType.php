<?php

namespace App\GraphQL\Mutations\OrderReview;

use App\Models\OrderReview;

final class SetOrderReviewErrorType
{
    /**
     * Проставить тип ошибки сборки у отзыва на заказ (null снимает отметку).
     * Отзывы приходят из витрины и наружу не публикуются, поэтому поле живёт
     * только в курьерской БД; входящее order_review.updated его не затирает
     * (OrderReviewEventTransformer::fromEvent поле не трогает).
     *
     * @param null $_
     * @param array{id: int, error_type?: string|null} $args
     */
    public function __invoke($_, array $args): OrderReview
    {
        // Селлер видит только отзывы на заказы своих магазинов — так же и правит.
        $review = OrderReview::query()->forCurrentUser()->findOrFail($args['id']);

        $errorType = $args['error_type'] ?? null;

        $review->update(['error_type' => $errorType !== '' ? $errorType : null]);

        return $review->fresh();
    }
}
