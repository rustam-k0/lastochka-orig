<?php

namespace App\Transformers;

use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Story;
use App\Support\EventDateTime;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StoryEventTransformer implements EventTransformer
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
        'product_id' => [
            'type' => Product::class,
            'source_field' => 'product_source',
            'id_field' => 'product_id',
            'nullable' => true,
        ],
        'shop_id' => [
            'type' => Shop::class,
            'source_field' => 'shop_source',
            'id_field' => 'shop_id',
            'nullable' => true,
        ],
    ];

    /**
     * Преобразовать модель Story в событие для RabbitMQ
     *
     * @param Story $story
     */
    public function toEvent(Model $story, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $story->updated_at ?? now(),
            'id' => (string) $story->id,
            'source' => config('rabbitmq-events.source'),
            'title' => $story->title,
            'expires_at' => $story->expires_at?->toIso8601String(),
            'link_type' => $story->link_type,
            'external_url' => $story->external_url,
            'position' => (int) $story->position,
            'is_active' => (bool) $story->is_active,
            'is_quick_category' => (bool) $story->is_quick_category,
            'thumbnail_image_url' => $this->getThumbnailImageUrl($story),
            'images' => $this->getSliderImages($story),
            'custom_checkboxes' => json_decode($story->custom_checkboxes ?: '{}', true) ?: [],
            'created_at' => $story->created_at?->toIso8601String(),
            'updated_at' => $story->updated_at?->toIso8601String(),
            'deleted_at' => $story->deleted_at?->toIso8601String(),
        ];

        // Добавить product_id + product_source через external reference
        if ($story->product_id) {
            $productMeta = EventSyncMetadata::where([
                'entity_type' => 'products',
                'entity_id' => $story->product_id,
            ])->first();

            if ($productMeta) {
                $eventData['product_id'] = $productMeta->external_id;
                $eventData['product_source'] = $productMeta->source;
            } else {
                $eventData['product_id'] = (string) $story->product_id;
                $eventData['product_source'] = config('rabbitmq-events.source');
            }
        }

        // Добавить shop_id + shop_source через external reference
        if ($story->shop_id) {
            $shopMeta = EventSyncMetadata::where([
                'entity_type' => 'shops',
                'entity_id' => $story->shop_id,
            ])->first();

            if ($shopMeta) {
                $eventData['shop_id'] = $shopMeta->external_id;
                $eventData['shop_source'] = $shopMeta->source;
            } else {
                // Для магазина можно использовать code как внешний идентификатор
                $shop = $story->shop;
                $eventData['shop_id'] = $shop?->code ?? (string) $story->shop_id;
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
            // Дату приводим к таймзоне приложения — издатель шлёт момент с
            // офсетом, а колонка таймзону не хранит (MID-272)
            'expires_at' => EventDateTime::fromPayload($data['expires_at'] ?? null) ?? now()->addDays(7),
            'link_type' => $data['link_type'] ?? Story::LINK_TYPE_NONE,
            'position' => $data['position'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'is_quick_category' => $data['is_quick_category'] ?? false,
        ];
    }

    /**
     * Получить URL превью изображения
     */
    protected function getThumbnailImageUrl(Story $story): ?string
    {
        if (!$story->relationLoaded('thumbnailImage')) {
            $story->load('thumbnailImage');
        }

        if ($story->thumbnailImage && $story->thumbnailImage->path) {
            return PathNormalizer::url($story->thumbnailImage->path);
        }

        return null;
    }

    /**
     * Получить массив URL слайдер изображений
     */
    protected function getSliderImages(Story $story): array
    {
        if (!$story->relationLoaded('images')) {
            $story->load('images');
        }

        $images = [];

        foreach ($story->images as $img) {
            if ($img->path) {
                $images[] = [
                    'id' => (string) $img->id,
                    'url' => PathNormalizer::url($img->path),
                    'alt' => $img->alt,
                ];
            }
        }

        return $images;
    }
}
