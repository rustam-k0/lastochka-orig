<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;

final class ReviewService
{
    /**
     * Может ли пользователь оставить отзыв на магазин.
     */
    public function canReviewStore(User $user, Store $store): bool
    {
        // Есть доставленный заказ в этом магазине
        $hasDeliveredOrder = Order::query()
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->where('status', OrderStatus::Delivered)
            ->exists();

        if (! $hasDeliveredOrder) {
            return false;
        }

        // Ещё не оставлял отзыв
        $hasReview = StoreReview::query()
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->exists();

        return ! $hasReview;
    }

    /**
     * Может ли пользователь оставить отзыв на товар.
     */
    public function canReviewProduct(User $user, Product $product): bool
    {
        // Покупал этот товар (есть в order_items доставленного заказа)
        $hasPurchased = OrderItem::query()
            ->whereHas('order', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('status', OrderStatus::Delivered);
            })
            ->where('product_id', $product->id)
            ->exists();

        if (! $hasPurchased) {
            return false;
        }

        // Ещё не оставлял отзыв
        $hasReview = ProductReview::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->exists();

        return ! $hasReview;
    }

    /**
     * Пересчитать рейтинг магазина на основе всех отзывов.
     */
    public function recalculateStoreRating(Store $store): void
    {
        $stats = StoreReview::query()
            ->where('store_id', $store->id)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')
            ->first();

        $store->update([
            'rating' => $stats->avg_rating ? round((float) $stats->avg_rating, 1) : null,
            'reviews_count' => $stats->count ?? 0,
        ]);
    }

    /**
     * Пересчитать рейтинг товара на основе всех отзывов.
     */
    public function recalculateProductRating(Product $product): void
    {
        $stats = ProductReview::query()
            ->where('product_id', $product->id)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')
            ->first();

        $product->update([
            'rating' => $stats->avg_rating ? round((float) $stats->avg_rating, 1) : null,
            'reviews_count' => $stats->count ?? 0,
        ]);
    }

    /**
     * Получить статистику рейтингов для магазина.
     *
     * @return array<string, int>
     */
    public function getStoreRatingsCount(Store $store): array
    {
        return $this->getRatingsCount(
            StoreReview::query()
                ->where('store_id', $store->id)
                ->where('is_published', true)
        );
    }

    /**
     * Получить статистику рейтингов для товара.
     *
     * @return array<string, int>
     */
    public function getProductRatingsCount(Product $product): array
    {
        return $this->getRatingsCount(
            ProductReview::query()
                ->where('product_id', $product->id)
                ->where('is_published', true)
        );
    }

    /**
     * Получить распределение рейтингов.
     *
     * @return array<string, int>
     */
    private function getRatingsCount($query): array
    {
        $counts = $query
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        // Гарантируем наличие всех ключей 1-5
        return [
            '5' => $counts[5] ?? 0,
            '4' => $counts[4] ?? 0,
            '3' => $counts[3] ?? 0,
            '2' => $counts[2] ?? 0,
            '1' => $counts[1] ?? 0,
        ];
    }
}
