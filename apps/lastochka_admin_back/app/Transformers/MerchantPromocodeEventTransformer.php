<?php

namespace App\Transformers;

use App\Models\MerchantPromocode;
use App\Models\Shop;
use App\Support\EventDateTime;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MerchantPromocodeEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesExternalReferences;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'title',
        'code',
    ];

    /**
     * Внешние ссылки для разрешения (при входящих событиях).
     * shop_id nullable — для platform-wide промокодов.
     */
    protected array $externalReferences = [
        'shop_id' => [
            'type' => Shop::class,
            'source_field' => 'shop_source',
            'id_field' => 'shop_id',
            'nullable' => true,
        ],
    ];

    /**
     * Преобразовать модель MerchantPromocode в событие для RabbitMQ
     *
     * @param MerchantPromocode $promocode
     */
    public function toEvent(Model $promocode, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            // pitfall #9: event_timestamp всегда now(), не updated_at — иначе
            // повторные публикации (retries, touch) теряются консьюмерами,
            // которые сверяют event_timestamp с последним обработанным.
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $promocode->id,
            'source' => config('rabbitmq-events.source'),
            'scope' => $promocode->scope ?? MerchantPromocode::SCOPE_STORE,
            'title' => $promocode->title,
            'code' => $promocode->code,
            'discount_percent' => (int) $promocode->discount_percent,
            'discount_type' => $promocode->discount_type ?? 'percent',
            'discount_value' => (int) ($promocode->discount_value ?? $promocode->discount_percent ?? 0),
            'binding_type' => $promocode->binding_type ?? 'all',
            'valid_from' => $promocode->valid_from?->toIso8601String(),
            'valid_to' => $promocode->valid_to?->toIso8601String(),
            'is_active' => (bool) $promocode->is_active,
            'is_valid' => (bool) $promocode->is_valid,
            'status' => $promocode->status,
            'max_uses' => $promocode->max_uses !== null ? (int) $promocode->max_uses : null,
            'max_uses_per_client' => $promocode->max_uses_per_client !== null ? (int) $promocode->max_uses_per_client : null,
            'used_count' => (int) ($promocode->used_count ?? 0),
            'created_at' => $promocode->created_at?->toIso8601String(),
            'updated_at' => $promocode->updated_at?->toIso8601String(),
            'deleted_at' => $promocode->deleted_at?->toIso8601String(),
        ];

        // Добавить shop_id + shop_source через external reference
        if ($promocode->shop_id) {
            $shopMeta = EventSyncMetadata::where([
                'entity_type' => 'shops',
                'entity_id' => $promocode->shop_id,
            ])->first();

            if ($shopMeta) {
                $eventData['shop_id'] = $shopMeta->external_id;
                $eventData['shop_source'] = $shopMeta->source;
            } else {
                $shop = $promocode->shop;
                $eventData['shop_id'] = $shop?->code ?? (string) $promocode->shop_id;
                $eventData['shop_source'] = config('rabbitmq-events.source');
            }
        }

        if ($promocode->binding_type === 'products' && $promocode->relationLoaded('products')) {
            $eventData['products'] = $promocode->products->map(function ($product) {
                $meta = EventSyncMetadata::where([
                    'entity_type' => 'products',
                    'entity_id' => $product->id,
                ])->first();

                return [
                    'product_id' => $meta ? $meta->external_id : (string) $product->id,
                    'product_source' => $meta ? $meta->source : config('rabbitmq-events.source'),
                ];
            })->toArray();
        }

        if ($promocode->binding_type === 'categories' && $promocode->relationLoaded('categories')) {
            $eventData['categories'] = $promocode->categories->map(function ($category) {
                $meta = EventSyncMetadata::where([
                    'entity_type' => 'categories',
                    'entity_id' => $category->id,
                ])->first();

                return [
                    'category_id' => $meta ? $meta->external_id : (string) $category->id,
                    'category_source' => $meta ? $meta->source : config('rabbitmq-events.source'),
                ];
            })->toArray();
        }

        return $eventData;
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий)
     * Не используется, так как мы только отправляем события
     */
    public function fromEvent(array $data): array
    {
        // Backward compat: 'electrohub' → 'platform' (in-flight RabbitMQ).
        $scope = $data['scope'] ?? MerchantPromocode::SCOPE_STORE;
        if ($scope === 'electrohub') {
            $scope = MerchantPromocode::SCOPE_PLATFORM;
        }

        return [
            'scope' => $scope,
            'title' => $data['title'] ?? 'Unknown',
            'code' => $data['code'] ?? 'UNKNOWN',
            'discount_percent' => $data['discount_percent'] ?? 0,
            // Даты приводим к таймзоне приложения — издатель шлёт момент с
            // офсетом, а колонка таймзону не хранит (MID-272)
            'valid_from' => EventDateTime::fromPayload($data['valid_from'] ?? null) ?? now(),
            'valid_to' => EventDateTime::fromPayload($data['valid_to'] ?? null) ?? now()->addDays(30),
            'is_active' => $data['is_active'] ?? true,
        ];
    }
}
