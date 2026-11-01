<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProductReviewEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'product_id', 'product_source', 'shop_id', 'shop_source', 'rating'];
    }

    /**
     * Преобразование модели ProductReview в payload события.
     *
     * @param  ProductReview  $review
     * @return array<string, mixed>
     */
    public function toEvent(Model $review, ?object $shop = null, ?string $eventId = null): array
    {
        $review->load(['product', 'store', 'user']);

        // Получить external_id товара
        $productMeta = EventSyncMetadata::where('entity_type', 'products')
            ->where('entity_id', $review->product_id)
            ->first();

        // Получить external_id магазина
        $storeMeta = EventSyncMetadata::where('entity_type', 'shops')
            ->where('entity_id', $review->store_id)
            ->first();

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $review->updated_at->toIso8601String(),
            'event_source' => config('rabbitmq-events.source'),
            'id' => (string) $review->id,
            'source' => config('rabbitmq-events.source'),
            'product_id' => $productMeta?->external_id ?? (string) $review->product_id,
            'product_source' => $productMeta?->source ?? config('rabbitmq-events.source'),
            'shop_id' => $storeMeta?->external_id ?? (string) $review->store_id,
            'shop_source' => $storeMeta?->source ?? config('rabbitmq-events.source'),
            'user_id' => (string) $review->user_id,
            'user_source' => config('rabbitmq-events.source'),
            'rating' => $review->rating,
            'description' => $review->description,
            'author_name' => $review->author_name,
            'author_phone' => $review->author_phone,
            'is_published' => $review->is_published,
            'created_at' => $review->created_at->toIso8601String(),
        ];
    }

    /**
     * Преобразование данных события в атрибуты модели ProductReview.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $productId = $this->resolveEntityId('products', $data['product_id'], $data['product_source']);
        $storeId = $this->resolveStoreIdByExternalId($data['shop_id'], $data['shop_source']);

        return [
            'product_id' => $productId,
            'store_id' => $storeId,
            'source' => $data['source'],
            'user_id' => null, // Внешние отзывы без привязки к user
            'rating' => (int) $data['rating'],
            'description' => $data['description'] ?? null,
            'author_name' => $data['author_name'] ?? null,
            'author_phone' => $data['author_phone'] ?? null,
            'is_published' => $data['is_published'] ?? false,
            'published_at' => ($data['is_published'] ?? false) ? now() : null,
        ];
    }

    /**
     * Перед созданием — проверить что товар и магазин существуют.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>|null
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (! $transformedData['product_id']) {
            Log::warning('ProductReview event: product not found', [
                'product_id' => $eventData['product_id'],
                'product_source' => $eventData['product_source'],
                'review_external_id' => $eventData['id'],
            ]);

            return null;
        }

        if (! $transformedData['store_id']) {
            Log::warning('ProductReview event: store not found', [
                'shop_id' => $eventData['shop_id'],
                'shop_source' => $eventData['shop_source'],
                'review_external_id' => $eventData['id'],
            ]);

            return null;
        }

        // Отзыв, созданный в админке с телефоном существующего клиента,
        // привязываем к этому клиенту — симметрично StoreReview
        // (ALADDIN-11937). Привязка невозможна при существующем отзыве
        // клиента на этот товар (UNIQUE user_id + product_id учитывает
        // и trashed-строки).
        if (! empty($transformedData['author_phone'])) {
            $user = User::where('phone', $transformedData['author_phone'])->first();

            if ($user) {
                $hasReview = ProductReview::withTrashed()
                    ->where('user_id', $user->id)
                    ->where('product_id', $transformedData['product_id'])
                    ->exists();

                if (! $hasReview) {
                    $transformedData['user_id'] = $user->id;
                }
            }
        }

        return $transformedData;
    }

    /**
     * После создания — пересчитать рейтинг товара.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $review, array $eventData): void
    {
        if ($review->is_published) {
            $this->recalculateProductRating($review->product_id);
        }

        Log::info('ProductReview created from event', [
            'review_id' => $review->id,
            'product_id' => $review->product_id,
            'store_id' => $review->store_id,
            'rating' => $review->rating,
            'is_published' => $review->is_published,
        ]);
    }

    /**
     * Перед обновлением — исключить user_id из данных обновления,
     * чтобы не сбрасывать привязку к пользователю при модерации отзыва.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>
     */
    public function beforeUpdate(Model $review, array $eventData, array $transformedData): array
    {
        // Не перезаписываем user_id — сохраняем привязку к пользователю
        unset($transformedData['user_id']);

        return $transformedData;
    }

    /**
     * После обновления — пересчитать рейтинг товара.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $review, array $eventData): void
    {
        $this->recalculateProductRating($review->product_id);
    }

    /**
     * После удаления — пересчитать рейтинг товара.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterDelete(Model $review, array $eventData): void
    {
        $this->recalculateProductRating($review->product_id);
    }

    /**
     * Пересчитать средний рейтинг товара.
     * Использует pessimistic lock для предотвращения race condition.
     */
    private function recalculateProductRating(int $productId): void
    {
        DB::transaction(function () use ($productId) {
            // Pessimistic lock на Product для предотвращения race condition
            Product::where('id', $productId)->lockForUpdate()->first();

            $stats = ProductReview::where('product_id', $productId)
                ->where('is_published', true)
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')
                ->first();

            $avgRating = $stats->avg_rating ? round((float) $stats->avg_rating, 1) : null;

            Product::where('id', $productId)->update([
                'rating' => $avgRating,
                'reviews_count' => $stats->count ?? 0,
            ]);

            Log::info('Product rating recalculated', [
                'product_id' => $productId,
                'rating' => $avgRating,
                'reviews_count' => $stats->count ?? 0,
            ]);
        });
    }
}
