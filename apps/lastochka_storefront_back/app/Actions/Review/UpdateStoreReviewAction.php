<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Models\StoreReview;
use App\Services\Review\ReviewService;

final class UpdateStoreReviewAction
{
    public function __construct(
        private ReviewService $reviewService,
    ) {}

    /**
     * Обновить отзыв на магазин.
     */
    public function execute(StoreReview $review, int $rating, ?string $description): StoreReview
    {
        $review->update([
            'rating' => $rating,
            'description' => $description,
        ]);

        // Пересчитать рейтинг магазина
        $this->reviewService->recalculateStoreRating($review->store);

        return $review->fresh();
    }
}
