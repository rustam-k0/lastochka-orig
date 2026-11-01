<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Banner;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class BannerEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'title', 'shop_id', 'shop_source'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $banner, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Banner.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveStoreId($data['shop_id'], $data['shop_source']);

        $attributes = [
            'store_id' => $storeId,
            'source' => $data['source'],
            'title' => $data['title'],
            'image_path' => '',
            'link_type' => $data['link_type'] ?? 'none',
            'link_product_id' => null,
            'link_category_id' => null,
            'display_category_id' => null,
            'is_active' => $data['is_active'] ?? true,
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
     * Перед созданием — проверить что магазин существует.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>|null
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (! $transformedData['store_id']) {
            Log::warning('Banner event: store not found', [
                'shop_id' => $eventData['shop_id'],
                'shop_source' => $eventData['shop_source'],
                'banner_external_id' => $eventData['id'],
            ]);

            return null;
        }

        return $transformedData;
    }

    /**
     * После создания — скачать изображение и связать с товаром/категорией.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $banner, array $eventData): void
    {
        $this->processImage($banner, $eventData);
        $this->linkReferences($banner, $eventData);
    }

    /**
     * После обновления — обновить изображение и ссылки.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $banner, array $eventData): void
    {
        if (isset($eventData['image_url'])) {
            $this->processImage($banner, $eventData);
        }

        $this->linkReferences($banner, $eventData);
    }

    /**
     * Скачать и сохранить изображение баннера.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processImage(Banner $banner, array $eventData): void
    {
        $imageUrl = $eventData['image_url'] ?? null;

        if (! $imageUrl) {
            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'banners');
            $banner->update(['image_path' => $localPath]);

            Log::info('Banner image downloaded', [
                'banner_id' => $banner->id,
                'image_path' => $localPath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download banner image', [
                'banner_id' => $banner->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Связать баннер с товаром или категорией.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function linkReferences(Banner $banner, array $eventData): void
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

                Log::info('Banner linked to product', [
                    'banner_id' => $banner->id,
                    'product_id' => $productId,
                ]);
            }
        }

        if ($linkType === 'category' && isset($eventData['category_id'], $eventData['category_source'])) {
            $categoryId = $this->resolveEntityId(
                'categories',
                $eventData['category_id'],
                $eventData['category_source']
            );

            if ($categoryId) {
                $updates['link_category_id'] = $categoryId;

                Log::info('Banner linked to category', [
                    'banner_id' => $banner->id,
                    'category_id' => $categoryId,
                ]);
            }
        }

        if (isset($eventData['display_category_id'], $eventData['display_category_source'])) {
            $displayCategoryId = $this->resolveEntityId(
                'categories',
                $eventData['display_category_id'],
                $eventData['display_category_source']
            );

            if ($displayCategoryId) {
                $updates['display_category_id'] = $displayCategoryId;

                Log::info('Banner bound to display category', [
                    'banner_id' => $banner->id,
                    'display_category_id' => $displayCategoryId,
                ]);
            }
        }

        if (! empty($updates)) {
            $banner->update($updates);
        }
    }

    /**
     * Найти магазин по external_id и source через metadata.
     */
    private function resolveStoreId(string $shopId, string $shopSource): ?int
    {
        // Сначала пробуем через metadata
        $storeEntityId = $this->resolveEntityId('shops', $shopId, $shopSource);

        if ($storeEntityId) {
            return $storeEntityId;
        }

        // Fallback: ищем по code
        return Store::where('code', $shopId)->value('id');
    }

    /**
     * Поиск существующего баннера по external_id и source в том же магазине.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $externalId = $eventData['id'] ?? null;
        $source = $eventData['source'] ?? null;

        // Пакет в delete/orphan-путях зовёт findExistingEntity($eventData, [])
        // с пустым $transformedData — store_id резолвим из события сами
        $storeId = $transformedData['store_id']
            ?? $this->resolveStoreId($eventData['shop_id'] ?? '', $eventData['shop_source'] ?? '');

        if (! $externalId || ! $source || ! $storeId) {
            return null;
        }

        return Banner::withTrashed()
            ->where('store_id', $storeId)
            ->where('external_id', $externalId)
            ->where('source', $source)
            ->first();
    }
}
