<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Promotion;
use App\Support\EventDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class PromotionEventTransformer extends BaseEventTransformer
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
    public function toEvent(Model $promotion, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Promotion.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveStoreIdByExternalId($data['shop_id'], $data['shop_source']);

        $attributes = [
            'store_id' => $storeId,
            'source' => $data['source'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'image_path' => null,
            'discount_percent' => (int) ($data['discount_percent'] ?? 0),
            // Даты приводим к таймзоне приложения — издатель шлёт момент с
            // офсетом, а колонка таймзону не хранит (MID-272)
            'starts_at' => EventDateTime::fromPayload($data['starts_at'] ?? null) ?? now(),
            'ends_at' => EventDateTime::fromPayload($data['ends_at'] ?? null) ?? now()->addMonth(),
            'is_active' => $data['is_active'] ?? true,
        ];

        if (array_key_exists('slug', $data) && $data['slug']) {
            $attributes['slug'] = $data['slug'];
        }

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
            Log::warning('Promotion event: store not found', [
                'shop_id' => $eventData['shop_id'],
                'shop_source' => $eventData['shop_source'],
                'promotion_external_id' => $eventData['id'],
            ]);

            return null;
        }

        return $transformedData;
    }

    /**
     * После создания — скачать изображение и синхронизировать товары.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $promotion, array $eventData): void
    {
        $this->processImage($promotion, $eventData);
        $this->syncProducts($promotion, $eventData);
    }

    /**
     * После обновления — обновить изображение и товары.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $promotion, array $eventData): void
    {
        if (isset($eventData['image_url'])) {
            $this->processImage($promotion, $eventData);
        }

        if (isset($eventData['products'])) {
            $this->syncProducts($promotion, $eventData);
        }
    }

    /**
     * Скачать и сохранить изображение акции.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processImage(Promotion $promotion, array $eventData): void
    {
        $imageUrl = $eventData['image_url'] ?? null;

        if (! $imageUrl) {
            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'promotions');
            $promotion->update(['image_path' => $localPath]);

            Log::info('Promotion image downloaded', [
                'promotion_id' => $promotion->id,
                'image_path' => $localPath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download promotion image', [
                'promotion_id' => $promotion->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Синхронизировать товары акции.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function syncProducts(Promotion $promotion, array $eventData): void
    {
        $products = $eventData['products'] ?? [];

        if (empty($products)) {
            return;
        }

        $productIds = [];

        foreach ($products as $item) {
            $productId = $this->resolveEntityId(
                'products',
                $item['product_id'] ?? null,
                $item['product_source'] ?? null
            );

            if ($productId) {
                $productIds[] = $productId;
            }
        }

        $promotion->products()->sync($productIds);

        Log::info('Promotion products synced', [
            'promotion_id' => $promotion->id,
            'products_count' => count($productIds),
        ]);
    }

    /**
     * Поиск существующей акции по external_id и source в том же магазине.
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
            ?? $this->resolveStoreIdByExternalId(
                (string) ($eventData['shop_id'] ?? ''),
                (string) ($eventData['shop_source'] ?? '')
            );

        if (! $externalId || ! $source || ! $storeId) {
            return null;
        }

        return Promotion::withTrashed()
            ->where('store_id', $storeId)
            ->where('external_id', $externalId)
            ->where('source', $source)
            ->first();
    }
}
