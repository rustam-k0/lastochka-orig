<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;
use App\Services\Review\ReviewService;

final class CreateStoreReviewAction
{
    public function __construct(
        private ReviewService $reviewService,
    ) {}

    /**
     * Создать отзыв на магазин.
     */
    public function execute(User $user, Store $store, int $rating, ?string $description): StoreReview
    {
        // UNIQUE(user_id, store_id) учитывает и soft-deleted строки: после
        // удаления отзыва прямой insert падает 1062 (ALADDIN-11937) —
        // восстанавливаем trashed-отзыв вместо создания нового.
        $review = StoreReview::withTrashed()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->first() ?? new StoreReview;

        $review->fill([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => $rating,
            'description' => $description,
            'author_name' => $user->short_name,
            'author_phone' => $user->phone,
            'is_published' => false,
        ]);

        if ($review->trashed()) {
            $review->restore();
        }

        $review->save();

        // Пересчитать рейтинг магазина (в ТЗ сказано пересчитывать при создании)
        $this->reviewService->recalculateStoreRating($store);

        return $review;
    }
}
