<?php

namespace App\Transformers;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Shop;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ResolvesShopCode;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductReviewEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesExternalReferences;
    use ResolvesShopCode;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'rating',
    ];

    /**
     * Внешние ссылки для разрешения (при входящих событиях)
     */
    protected array $externalReferences = [
        'shop_id' => [
            'type' => Shop::class,
            'source_field' => 'shop_source',
            'id_field' => 'shop_id',
            'nullable' => false,
        ],
        'product_id' => [
            'type' => Product::class,
            'source_field' => 'product_source',
            'id_field' => 'product_id',
            'nullable' => false,
        ],
    ];

    /**
     * Преобразовать модель ProductReview в событие для RabbitMQ
     *
     * @param ProductReview $review
     */
    public function toEvent(Model $review, ?object $relatedModel = null, ?string $eventId = null): array
    {
        // Получить metadata для отзыва (если пришёл из внешнего источника)
        $reviewMetadata = EventSyncMetadata::where([
            'entity_type' => 'product_reviews',
            'entity_id' => $review->id,
        ])->first();

        // Если есть metadata — используем external_id и source из неё
        $reviewId = $reviewMetadata ? $reviewMetadata->external_id : (string) $review->id;
        $reviewSource = $reviewMetadata ? $reviewMetadata->source : config('rabbitmq-events.source');

        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $review->updated_at ?? now(),
            'id' => $reviewId,
            'source' => $reviewSource,
            'rating' => (int) $review->rating,
            'description' => $review->description,
            'is_published' => (bool) $review->is_published,
            'author_name' => $review->author_name,
            'author_phone' => $review->author_phone,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
            'deleted_at' => $review->deleted_at?->toIso8601String(),
        ];

        // Добавить shop_id + shop_source через external reference
        if ($review->shop_id) {
            $shopMeta = EventSyncMetadata::where([
                'entity_type' => 'shops',
                'entity_id' => $review->shop_id,
            ])->first();

            if ($shopMeta) {
                $eventData['shop_id'] = $shopMeta->external_id;
                $eventData['shop_source'] = $shopMeta->source;
            } else {
                $shop = $review->shop;
                $eventData['shop_id'] = $shop?->code ?? (string) $review->shop_id;
                $eventData['shop_source'] = config('rabbitmq-events.source');
            }
        }

        // Добавить product_id + product_source через external reference
        if ($review->product_id) {
            $productMeta = EventSyncMetadata::where([
                'entity_type' => 'products',
                'entity_id' => $review->product_id,
            ])->first();

            if ($productMeta) {
                $eventData['product_id'] = $productMeta->external_id;
                $eventData['product_source'] = $productMeta->source;
            } else {
                $eventData['product_id'] = (string) $review->product_id;
                $eventData['product_source'] = config('rabbitmq-events.source');
            }
        }

        return $eventData;
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий)
     */
    public function fromEvent(array $data): array
    {
        return [
            'rating' => $data['rating'] ?? 5,
            'description' => $data['description'] ?? null,
            'is_published' => $data['is_published'] ?? false,
            'author_name' => $data['author_name'] ?? null,
            'author_phone' => $data['author_phone'] ?? null,
        ];
    }

    /**
     * Хук перед созданием — резолвим обязательные внешние ссылки
     *
     * shop_id и product_id являются NOT NULL в БД, поэтому их нужно
     * зарезолвить ДО сохранения модели, а не в afterCreate
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        $currentSource = config('rabbitmq-events.source');

        // Резолвим shop_id через ResolvesShopCode трейт
        // shop_id в событии — это shop_code (например "aladdinmarketplace")
        if (isset($eventData['shop_id'])) {
            $shopCode = $eventData['shop_id'];
            $shopSource = $eventData['shop_source'] ?? $currentSource;

            // Используем resolveShopId из трейта ResolvesShopCode
            // Он ищет по code в shops, по external_id в metadata, или создаёт новый
            $localShopId = $this->resolveShopId($shopCode);

            if ($localShopId) {
                $transformedData['shop_id'] = $localShopId;
                \Illuminate\Support\Facades\Log::debug('Shop resolved for ProductReview', [
                    'shop_code' => $shopCode,
                    'shop_source' => $shopSource,
                    'local_shop_id' => $localShopId,
                ]);
            } else {
                \Illuminate\Support\Facades\Log::warning('Cannot create ProductReview: shop not found', [
                    'event_id' => $eventData['event_id'] ?? 'unknown',
                    'shop_code' => $shopCode,
                    'shop_source' => $shopSource,
                ]);
                return null;
            }
        } else {
            \Illuminate\Support\Facades\Log::warning('Cannot create ProductReview: shop_id missing', [
                'event_id' => $eventData['event_id'] ?? 'unknown',
            ]);
            return null;
        }

        // Резолвим product_id через external reference (без shop_code scope)
        if (isset($eventData['product_source']) && isset($eventData['product_id'])) {
            $source = $eventData['product_source'];
            $externalId = $eventData['product_id'];

            if ($source === $currentSource) {
                $transformedData['product_id'] = $externalId;
            } else {
                $localId = $this->resolveExternalReferenceGlobal(
                    Product::class,
                    $source,
                    $externalId
                );

                if ($localId) {
                    $transformedData['product_id'] = $localId;
                } else {
                    \Illuminate\Support\Facades\Log::warning('Cannot create ProductReview: product not found', [
                        'event_id' => $eventData['event_id'] ?? 'unknown',
                        'product_source' => $source,
                        'product_id' => $externalId,
                    ]);
                    return null;
                }
            }
        } else {
            \Illuminate\Support\Facades\Log::warning('Cannot create ProductReview: product_id/product_source missing', [
                'event_id' => $eventData['event_id'] ?? 'unknown',
            ]);
            return null;
        }

        return $transformedData;
    }
}
