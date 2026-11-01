<?php

namespace App\Transformers;

use App\Models\MerchantSale;
use App\Models\Shop;
use App\Support\EventDateTime;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MerchantSaleEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesExternalReferences;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'title',
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
    ];

    /**
     * Преобразовать модель MerchantSale в событие для RabbitMQ
     *
     * @param MerchantSale $sale
     */
    public function toEvent(Model $sale, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            // pitfall #9: event_timestamp всегда now(), не updated_at — иначе
            // повторные публикации (retries, touch) теряются консьюмерами.
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $sale->id,
            'source' => config('rabbitmq-events.source'),
            'title' => $sale->title,
            'slug' => $sale->link,
            'description' => $sale->description,
            'discount_percent' => (int) $sale->discount_percent,
            'starts_at' => $sale->starts_at?->toIso8601String(),
            'ends_at' => $sale->ends_at?->toIso8601String(),
            'is_active' => (bool) $sale->is_active,
            'status' => $sale->status,
            'image_url' => $this->getImageUrl($sale),
            'products' => $this->getProductIds($sale),
            'custom_checkboxes' => json_decode($sale->custom_checkboxes ?: '{}', true) ?: [],
            'created_at' => $sale->created_at?->toIso8601String(),
            'updated_at' => $sale->updated_at?->toIso8601String(),
            'deleted_at' => $sale->deleted_at?->toIso8601String(),
        ];

        // Добавить shop_id + shop_source через external reference
        if ($sale->shop_id) {
            $shopMeta = EventSyncMetadata::where([
                'entity_type' => 'shops',
                'entity_id' => $sale->shop_id,
            ])->first();

            if ($shopMeta) {
                $eventData['shop_id'] = $shopMeta->external_id;
                $eventData['shop_source'] = $shopMeta->source;
            } else {
                $shop = $sale->shop;
                $eventData['shop_id'] = $shop?->code ?? (string) $sale->shop_id;
                $eventData['shop_source'] = config('rabbitmq-events.source');
            }
        }

        return $eventData;
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий)
     * Не используется, так как мы только отправляем события
     */
    public function fromEvent(array $data): array
    {
        return [
            'title' => $data['title'] ?? 'Unknown',
            'description' => $data['description'] ?? null,
            'discount_percent' => $data['discount_percent'] ?? 0,
            // Даты приводим к таймзоне приложения — издатель шлёт момент с
            // офсетом, а колонка таймзону не хранит (MID-272)
            'starts_at' => EventDateTime::fromPayload($data['starts_at'] ?? null) ?? now(),
            'ends_at' => EventDateTime::fromPayload($data['ends_at'] ?? null) ?? now()->addDays(7),
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    /**
     * Получить URL изображения акции
     */
    protected function getImageUrl(MerchantSale $sale): ?string
    {
        if (!$sale->relationLoaded('image')) {
            $sale->load('image');
        }

        if ($sale->image && $sale->image->path) {
            return PathNormalizer::url($sale->image->path);
        }

        return null;
    }

    /**
     * Получить массив товаров в акции с external references
     */
    protected function getProductIds(MerchantSale $sale): array
    {
        if (!$sale->relationLoaded('products')) {
            $sale->load('products');
        }

        $products = [];

        foreach ($sale->products as $product) {
            $productMeta = EventSyncMetadata::where([
                'entity_type' => 'products',
                'entity_id' => $product->id,
            ])->first();

            if ($productMeta) {
                $products[] = [
                    'product_id' => $productMeta->external_id,
                    'product_source' => $productMeta->source,
                ];
            } else {
                $products[] = [
                    'product_id' => (string) $product->id,
                    'product_source' => config('rabbitmq-events.source'),
                ];
            }
        }

        return $products;
    }
}
