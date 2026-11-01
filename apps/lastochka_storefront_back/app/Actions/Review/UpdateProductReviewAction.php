<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Models\ProductReview;
use App\Services\Review\ReviewService;

final class UpdateProductReviewAction
{
    public function __construct(
        private ReviewService $reviewService,
    ) {}

    /**
     * Обновить отзыв на товар.
     */
    public function execute(ProductReview $review, int $rating, ?string $description): ProductReview
    {
        $review->update([
            'rating' => $rating,
            'description' => $description,
        ]);

        // Пересчитать рейтинг товара
        $this->reviewService->recalculateProductRating($review->product);

        return $review->fresh();
    }
}
