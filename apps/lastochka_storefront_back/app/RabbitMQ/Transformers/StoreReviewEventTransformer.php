<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class StoreReviewEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'shop_id', 'shop_source', 'rating'];
    }

    /**
     * Преобразование модели StoreReview в payload события.
     *
     * @param  StoreReview  $review
     * @return array<string, mixed>
     */
    public function toEvent(Model $review, ?object $shop = null, ?string $eventId = null): array
    {
        $review->load(['store', 'user']);

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
     * Преобразование данных события в атрибуты модели StoreReview.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveStoreIdByExternalId($data['shop_id'], $data['shop_source']);

        return [
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
     * Перед созданием — проверить что магазин существует.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>|null
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (! $transformedData['store_id']) {
            Log::warning('StoreReview event: store not found', [
                'shop_id' => $eventData['shop_id'],
                'shop_source' => $eventData['shop_source'],
                'review_external_id' => $eventData['id'],
            ]);

            return null;
        }

        // Отзыв, созданный в админке с телефоном существующего клиента,
        // привязываем к этому клиенту — тогда GET /stores/{id}/reviews/my
        // отдаст его и мобилка предложит редактирование, а не создание
        // дубля (ALADDIN-11937). Привязка невозможна, если у клиента уже
        // есть отзыв на этот магазин (UNIQUE user_id + store_id учитывает
        // и trashed-строки).
        if (! empty($transformedData['author_phone'])) {
            $user = User::where('phone', $transformedData['author_phone'])->first();

            if ($user) {
                $hasReview = StoreReview::withTrashed()
                    ->where('user_id', $user->id)
                    ->where('store_id', $transformedData['store_id'])
                    ->exists();

                if (! $hasReview) {
                    $transformedData['user_id'] = $user->id;
                }
            }
        }

        return $transformedData;
    }

    /**
     * После создания — пересчитать рейтинг магазина.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $review, array $eventData): void
    {
        if ($review->is_published) {
            $this->recalculateStoreRating($review->store_id);
        }

        Log::info('StoreReview created from event', [
            'review_id' => $review->id,
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
     * После обновления — пересчитать рейтинг магазина.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $review, array $eventData): void
    {
        $this->recalculateStoreRating($review->store_id);
    }

    /**
     * После удаления — пересчитать рейтинг магазина.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterDelete(Model $review, array $eventData): void
    {
        $this->recalculateStoreRating($review->store_id);
    }

    /**
     * Пересчитать средний рейтинг магазина.
     * Использует pessimistic lock для предотвращения race condition.
     */
    private function recalculateStoreRating(int $storeId): void
    {
        DB::transaction(function () use ($storeId) {
            // Pessimistic lock на Store для предотвращения race condition
            Store::where('id', $storeId)->lockForUpdate()->first();

            $stats = StoreReview::where('store_id', $storeId)
                ->where('is_published', true)
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')
                ->first();

            $avgRating = $stats->avg_rating ? round((float) $stats->avg_rating, 1) : null;

            Store::where('id', $storeId)->update([
                'rating' => $avgRating,
                'reviews_count' => $stats->count ?? 0,
            ]);

            Log::info('Store rating recalculated', [
                'store_id' => $storeId,
                'rating' => $avgRating,
                'reviews_count' => $stats->count ?? 0,
            ]);
        });
    }
}
