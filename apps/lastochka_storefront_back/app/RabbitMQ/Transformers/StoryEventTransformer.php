<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Story;
use App\Models\StoryImage;
use App\Support\EventDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class StoryEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'title'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $story, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Story.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $linkType = $data['link_type'] ?? 'none';

        // Нормализация: внешняя система шлёт "shop", мобилка ожидает "store"
        if ($linkType === 'shop') {
            $linkType = 'store';
        }

        $attributes = [
            'source' => $data['source'],
            'title' => $data['title'],
            // Даты приводим к таймзоне приложения — издатель шлёт момент с
            // офсетом, а колонка таймзону не хранит (MID-272)
            'expires_at' => EventDateTime::fromPayload($data['expires_at'] ?? null) ?? now()->addMonth(),
            'link_type' => $linkType,
            'link_product_id' => null,
            'link_store_id' => null,
            'link_external_url' => $data['external_url'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_quick_category' => $data['is_quick_category'] ?? false,
            'thumbnail_path' => '',
        ];

        // Динамические чекбоксы: conditional set — события без ключа
        // не затирают локальные значения.
        if (array_key_exists('custom_checkboxes', $data)) {
            $attributes['custom_checkboxes'] = is_array($data['custom_checkboxes'])
                ? array_map(fn ($v) => (bool) $v, $data['custom_checkboxes'])
                : [];
        }

        // external_id нужен findExistingEntity(): по нему пакет находит сущность, когда
        // метаданных нет или entity_id в них потерян (иначе удаление оставляет зомби).
        // Conditional set — как у остальных полей: payload без id его не затирает.
        if (isset($data['id'])) {
            $attributes['external_id'] = (string) $data['id'];
        }

        return $attributes;
    }

    /**
     * После создания — скачать изображения и связать с товаром/магазином.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $story, array $eventData): void
    {
        $this->processThumbnail($story, $eventData);
        $this->processImages($story, $eventData);
        $this->linkReferences($story, $eventData);
    }

    /**
     * После обновления — обновить связи и изображения.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $story, array $eventData): void
    {
        if (isset($eventData['thumbnail_image_url'])) {
            $this->processThumbnail($story, $eventData);
        }

        if (isset($eventData['images'])) {
            $this->processImages($story, $eventData);
        }

        $this->linkReferences($story, $eventData);
    }

    /**
     * Скачать и сохранить thumbnail истории.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processThumbnail(Story $story, array $eventData): void
    {
        $imageUrl = $eventData['thumbnail_image_url'] ?? null;

        if (! $imageUrl) {
            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'stories/thumbnails');
            $story->update(['thumbnail_path' => $localPath]);

            Log::info('Story thumbnail downloaded', [
                'story_id' => $story->id,
                'image_path' => $localPath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download story thumbnail', [
                'story_id' => $story->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Скачать и сохранить изображения истории.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processImages(Story $story, array $eventData): void
    {
        $images = $eventData['images'] ?? [];

        if (empty($images)) {
            return;
        }

        // Удаляем старые изображения
        $story->images()->delete();

        foreach ($images as $index => $image) {
            $imageUrl = $image['url'] ?? null;

            if (! $imageUrl) {
                continue;
            }

            try {
                $localPath = $this->downloadImage($imageUrl, 'stories');

                StoryImage::create([
                    'story_id' => $story->id,
                    'path' => $localPath,
                    'position' => $index,
                ]);

                Log::info('Story image downloaded', [
                    'story_id' => $story->id,
                    'image_path' => $localPath,
                    'position' => $index,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to download story image', [
                    'story_id' => $story->id,
                    'image_url' => $imageUrl,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Связать историю с товаром или магазином.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function linkReferences(Story $story, array $eventData): void
    {
        $linkType = $eventData['link_type'] ?? 'none';
        $updates = [];

        if ($linkType === 'product' && isset($eventData['product_id'], $eventData['product_source'])) {
            $productId = $this->resolveEntityId(
                'products',
                $eventData['product_id'],
                $eventData['product_source']
            );

            if ($productId) {
                $updates['link_product_id'] = $productId;

                Log::info('Story linked to product', [
                    'story_id' => $story->id,
                    'product_id' => $productId,
                ]);
            }
        }

        if (in_array($linkType, ['store', 'shop'], true) && isset($eventData['shop_id'], $eventData['shop_source'])) {
            $storeId = $this->resolveEntityId(
                'shops',
                $eventData['shop_id'],
                $eventData['shop_source']
            );

            if ($storeId) {
                $updates['link_store_id'] = $storeId;

                Log::info('Story linked to store', [
                    'story_id' => $story->id,
                    'store_id' => $storeId,
                ]);
            }
        }

        if (! empty($updates)) {
            $story->update($updates);
        }
    }

    /**
     * Поиск существующей истории по external_id и source.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $externalId = $eventData['id'] ?? null;
        $source = $eventData['source'] ?? null;

        if (! $externalId || ! $source) {
            return null;
        }

        return Story::withTrashed()
            ->where('external_id', $externalId)
            ->where('source', $source)
            ->first();
    }
}
