<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Exceptions\OrderAlreadyHasReviewException;
use App\Models\Order;
use App\Models\OrderReview;
use App\Models\User;
use Illuminate\Database\QueryException;

final class CreateOrderReviewAction
{
    /**
     * Создать отзыв на заказ.
     *
     * Проверки авторизации выполняются в OrderReviewPolicy::createForOrder()
     *
     * @throws OrderAlreadyHasReviewException
     */
    public function execute(User $user, Order $order, int $rating, ?string $description): OrderReview
    {
        $review = new OrderReview([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'rating' => $rating,
            'description' => $description,
            'author_name' => $user->short_name,
            'author_phone' => $user->phone,
            'source' => 'storefront',
        ]);

        try {
            $review->save();
        } catch (QueryException $e) {
            // Обработка unique constraint violation (order_id, user_id)
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new OrderAlreadyHasReviewException('На этот заказ уже оставлен отзыв');
            }
            throw $e;
        }

        return $review;
    }
}
