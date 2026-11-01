<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Exceptions\OrderReviewNotOwnedException;
use App\Models\OrderReview;
use App\Models\User;

final class UpdateOrderReviewAction
{
    /**
     * Обновить отзыв на заказ.
     *
     * @throws OrderReviewNotOwnedException
     */
    public function execute(User $user, OrderReview $review, int $rating, ?string $description): OrderReview
    {
        // Проверяем, что отзыв принадлежит пользователю
        if ($review->user_id !== $user->id) {
            throw new OrderReviewNotOwnedException('Вы не можете изменить чужой отзыв');
        }

        $review->update([
            'rating' => $rating,
            'description' => $description,
        ]);

        return $review;
    }
}
