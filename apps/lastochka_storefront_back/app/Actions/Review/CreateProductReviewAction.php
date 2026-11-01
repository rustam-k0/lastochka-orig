<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\Review\ReviewService;

final class CreateProductReviewAction
{
    public function __construct(
        private ReviewService $reviewService,
    ) {}

    /**
     * Создать отзыв на товар.
     */
    public function execute(User $user, Product $product, int $rating, ?string $description): ProductReview
    {
        // UNIQUE(user_id, product_id) учитывает и soft-deleted строки: после
        // удаления отзыва прямой insert падает 1062 (ALADDIN-11937) —
        // восстанавливаем trashed-отзыв вместо создания нового.
        $review = ProductReview::withTrashed()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->first() ?? new ProductReview;

        $review->fill([
            'product_id' => $product->id,
            'store_id' => $product->store_id,
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

        // Пересчитать рейтинг товара
        $this->reviewService->recalculateProductRating($product);

        return $review;
    }
}
