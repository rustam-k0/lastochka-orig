<?php

namespace App\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderReview;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderReviewEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesExternalReferences;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'rating',
    ];

    protected array $externalReferences = [
        'order_id' => [
            'type' => Order::class,
            'source_field' => 'order_source',
            'id_field' => 'order_id',
            'nullable' => false,
        ],
        'user_id' => [
            'type' => Client::class,
            'source_field' => 'user_source',
            'id_field' => 'user_id',
            'nullable' => true,
            'shop_code_scoped' => false,
        ],
    ];

    /**
     * @param OrderReview $review
     */
    public function toEvent(Model $review, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $reviewMetadata = EventSyncMetadata::where([
            'entity_type' => 'order_reviews',
            'entity_id' => $review->id,
        ])->first();

        $reviewId = $reviewMetadata ? $reviewMetadata->external_id : (string) $review->id;
        $reviewSource = $reviewMetadata ? $reviewMetadata->source : ($review->source ?: config('rabbitmq-events.source'));

        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $review->updated_at ?? now(),
            'id' => $reviewId,
            'source' => $reviewSource,
            'rating' => (int) $review->rating,
            'description' => $review->description,
            'author_name' => $review->author_name,
            'author_phone' => $review->author_phone,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
            'deleted_at' => $review->deleted_at?->toIso8601String(),
        ];

        if ($review->order_id) {
            $orderMeta = EventSyncMetadata::where([
                'entity_type' => 'orders',
                'entity_id' => $review->order_id,
            ])->first();

            if ($orderMeta) {
                $eventData['order_id'] = $orderMeta->external_id;
                $eventData['order_source'] = $orderMeta->source;
            } else {
                $eventData['order_id'] = (string) $review->order_id;
                $eventData['order_source'] = config('rabbitmq-events.source');
            }
        }

        if ($review->user_id) {
            $userMeta = EventSyncMetadata::where([
                'entity_type' => 'customers',
                'entity_id' => $review->user_id,
            ])->first();

            if ($userMeta) {
                $eventData['user_id'] = $userMeta->external_id;
                $eventData['user_source'] = $userMeta->source;
            } else {
                $eventData['user_id'] = (string) $review->user_id;
                $eventData['user_source'] = config('rabbitmq-events.source');
            }
        }

        return $eventData;
    }

    public function fromEvent(array $data): array
    {
        $attributes = [
            'rating' => $data['rating'],
            'description' => $data['description'] ?? null,
            'author_name' => $data['author_name'] ?? null,
            'author_phone' => $data['author_phone'] ?? null,
        ];

        if (array_key_exists('source', $data)) {
            $attributes['source'] = $data['source'];
        }

        return $attributes;
    }

    /**
     * order_id обязателен в БД → резолвим до save.
     * user_id опционален → резолвим best-effort.
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        $currentSource = config('rabbitmq-events.source');

        if (isset($eventData['order_source']) && isset($eventData['order_id'])) {
            $source = $eventData['order_source'];
            $externalId = $eventData['order_id'];

            if ($source === $currentSource) {
                $transformedData['order_id'] = $externalId;
            } else {
                $localId = $this->resolveExternalReferenceGlobal(
                    Order::class,
                    $source,
                    $externalId
                );

                if ($localId) {
                    $transformedData['order_id'] = $localId;
                } else {
                    Log::warning('Cannot create OrderReview: order not found', [
                        'event_id' => $eventData['event_id'] ?? 'unknown',
                        'order_source' => $source,
                        'order_id' => $externalId,
                    ]);
                    return null;
                }
            }
        } else {
            Log::warning('Cannot create OrderReview: order_id/order_source missing', [
                'event_id' => $eventData['event_id'] ?? 'unknown',
            ]);
            return null;
        }

        if (isset($eventData['user_source']) && isset($eventData['user_id'])) {
            $userSource = $eventData['user_source'];
            $userExternalId = $eventData['user_id'];

            if ($userSource === $currentSource) {
                $transformedData['user_id'] = $userExternalId;
            } else {
                $localUserId = $this->resolveExternalReferenceGlobal(
                    Client::class,
                    $userSource,
                    $userExternalId
                );

                if ($localUserId) {
                    $transformedData['user_id'] = $localUserId;
                } else {
                    Log::info('OrderReview author not resolved locally; keeping user_id null', [
                        'event_id' => $eventData['event_id'] ?? 'unknown',
                        'user_source' => $userSource,
                        'user_id' => $userExternalId,
                    ]);
                }
            }
        }

        return $transformedData;
    }
}
