<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductReview;
use App\Models\StoreReview;
use App\Models\User;

final class ReviewPolicy
{
    /**
     * Обновление отзыва на магазин.
     */
    public function updateStoreReview(User $user, StoreReview $review): bool
    {
        return $user->id === $review->user_id;
    }

    /**
     * Обновление отзыва на товар.
     */
    public function updateProductReview(User $user, ProductReview $review): bool
    {
        return $user->id === $review->user_id;
    }
}
