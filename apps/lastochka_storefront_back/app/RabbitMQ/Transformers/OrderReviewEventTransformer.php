<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\OrderReview;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OrderReviewEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'order_id', 'order_source', 'user_id', 'user_source', 'rating'];
    }

    /**
     * Преобразование модели OrderReview в payload события.
     *
     * @param  OrderReview  $review
     * @return array<string, mixed>
     */
    public function toEvent(Model $review, ?object $shop = null, ?string $eventId = null): array
    {
        $review->load(['order', 'user']);

        // Получить external_id заказа
        $orderMeta = EventSyncMetadata::where('entity_type', 'orders')
            ->where('entity_id', $review->order_id)
            ->first();

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $review->updated_at->toIso8601String(),
            'event_source' => config('rabbitmq-events.source'),
            'id' => (string) $review->id,
            'source' => config('rabbitmq-events.source'),
            'order_id' => $orderMeta?->external_id ?? (string) $review->order_id,
            'order_source' => $orderMeta?->source ?? config('rabbitmq-events.source'),
            'user_id' => (string) $review->user_id,
            'user_source' => config('rabbitmq-events.source'),
            'rating' => $review->rating,
            'description' => $review->description,
            'author_name' => $review->author_name,
            'author_phone' => $review->author_phone,
            'created_at' => $review->created_at->toIso8601String(),
        ];
    }

    /**
     * Этот трансформер используется только для отправки событий,
     * поэтому fromEvent не нужен.
     */
    public function fromEvent(array $data): array
    {
        Log::warning('OrderReviewEventTransformer::fromEvent called but not implemented', [
            'data' => $data,
        ]);

        return [];
    }

    /**
     * После создания отзыва — логируем отправку события.
     */
    public function afterCreate(Model $review, array $eventData): void
    {
        Log::info('OrderReview created and sent to RabbitMQ', [
            'review_id' => $review->id,
            'order_id' => $review->order_id,
            'user_id' => $review->user_id,
            'rating' => $review->rating,
        ]);
    }

    /**
     * После обновления отзыва — логируем отправку события.
     */
    public function afterUpdate(Model $review, array $eventData): void
    {
        Log::info('OrderReview updated and sent to RabbitMQ', [
            'review_id' => $review->id,
            'order_id' => $review->order_id,
            'user_id' => $review->user_id,
            'rating' => $review->rating,
        ]);
    }
}