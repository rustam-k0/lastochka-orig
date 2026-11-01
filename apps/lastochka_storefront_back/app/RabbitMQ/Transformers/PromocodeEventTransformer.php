<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Promocode;
use App\Models\Store;
use App\Support\EventDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class PromocodeEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        // shop_id/shop_source больше не обязательны — platform-wide
        // промокоды приходят без привязки к магазину.
        return ['event_id', 'id', 'source', 'title', 'code'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $promocode, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Promocode.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        // Legacy события без scope — считаем 'store' для backward compat.
        // Backward compat: 'electrohub' → 'platform' (in-flight RabbitMQ).
        $scope = $data['scope'] ?? Promocode::SCOPE_STORE;
        if ($scope === 'electrohub') {
            $scope = Promocode::SCOPE_PLATFORM;
        }

        // Для platform-wide промокодов shop_id не приходит — store_id остаётся null.
        $storeId = null;
        if ($scope !== Promocode::SCOPE_PLATFORM && ! empty($data['shop_id'])) {
            $storeId = $this->resolveStoreId($data['shop_id'], $data['shop_source'] ?? '');
        }

        $attributes = [
            'store_id' => $storeId,
            'scope' => $scope,
            'source' => $data['source'],
            'title' => $data['title'],
            'code' => $data['code'],
            'type' => $data['discount_type'] ?? 'percent',
            'value' => (int) ($data['discount_value'] ?? $data['discount_percent'] ?? 0),
            'binding_type' => $data['binding_type'] ?? 'all',
            // Даты приводим к таймзоне приложения — издатель шлёт момент с
            // офсетом, а колонка таймзону не хранит (MID-272)
            'starts_at' => EventDateTime::fromPayload($data['valid_from'] ?? null) ?? now(),
            'ends_at' => EventDateTime::fromPayload($data['valid_to'] ?? null) ?? now()->addMonth(),
            'is_active' => $data['is_active'] ?? true,
            'max_uses' => $data['max_uses'] ?? null,
            'max_uses_per_client' => $data['max_uses_per_client'] ?? null,
        ];

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
        $isPlatformWide = ($transformedData['scope'] ?? Promocode::SCOPE_STORE) === Promocode::SCOPE_PLATFORM;

        // Platform-wide промокоды валидны без store_id.
        if (! $isPlatformWide && ! $transformedData['store_id']) {
            Log::warning('Promocode event: store not found', [
                'shop_id' => $eventData['shop_id'] ?? null,
                'shop_source' => $eventData['shop_source'] ?? null,
                'promocode_external_id' => $eventData['id'],
            ]);

            return null;
        }

        return $transformedData;
    }

    /**
     * После создания — логируем.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $promocode, array $eventData): void
    {
        Log::info('Promocode created from event', [
            'promocode_id' => $promocode->id,
            'code' => $promocode->code,
            'store_id' => $promocode->store_id,
        ]);

        $this->syncBindings($promocode, $eventData);
    }

    /**
     * После обновления — синхронизируем привязки.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $promocode, array $eventData): void
    {
        $this->syncBindings($promocode, $eventData);
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
     * Поиск существующего промокода по code в том же магазине.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $code = $eventData['code'] ?? null;

        if (! $code) {
            return null;
        }

        $isPlatformWide = ($transformedData['scope'] ?? Promocode::SCOPE_STORE) === Promocode::SCOPE_PLATFORM;

        if ($isPlatformWide) {
            // Platform-wide: уникальность по code + scope, без store_id.
            return Promocode::whereNull('store_id')
                ->where('scope', Promocode::SCOPE_PLATFORM)
                ->where('code', $code)
                ->first();
        }

        // Пакет в delete/orphan-путях зовёт findExistingEntity($eventData, [])
        // с пустым $transformedData — store_id резолвим из события сами
        $storeId = $transformedData['store_id']
            ?? $this->resolveStoreId($eventData['shop_id'] ?? '', $eventData['shop_source'] ?? '');

        if (! $storeId) {
            return null;
        }

        // Store-scoped: уникальность по code в том же магазине.
        return Promocode::where('store_id', $storeId)
            ->where('scope', Promocode::SCOPE_STORE)
            ->where('code', $code)
            ->first();
    }

    /**
     * Синхронизация привязок промокода к товарам/категориям.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function syncBindings(Model $promocode, array $eventData): void
    {
        $bindingType = $eventData['binding_type'] ?? 'all';

        if ($bindingType === 'products' && ! empty($eventData['products'])) {
            $productIds = [];
            foreach ($eventData['products'] as $item) {
                $productId = $this->resolveEntityId(
                    'products',
                    $item['product_id'] ?? null,
                    $item['product_source'] ?? null
                );
                if ($productId) {
                    $productIds[] = $productId;
                }
            }
            $promocode->products()->sync($productIds);
            $promocode->categories()->detach();

            Log::info('Promocode products synced', [
                'promocode_id' => $promocode->id,
                'products_count' => count($productIds),
            ]);
        } elseif ($bindingType === 'categories' && ! empty($eventData['categories'])) {
            $categoryIds = [];
            foreach ($eventData['categories'] as $item) {
                $categoryId = $this->resolveEntityId(
                    'categories',
                    $item['category_id'] ?? null,
                    $item['category_source'] ?? null
                );
                if ($categoryId) {
                    $categoryIds[] = $categoryId;
                }
            }
            $promocode->categories()->sync($categoryIds);
            $promocode->products()->detach();

            Log::info('Promocode categories synced', [
                'promocode_id' => $promocode->id,
                'categories_count' => count($categoryIds),
            ]);
        } else {
            $promocode->products()->detach();
            $promocode->categories()->detach();
        }
    }
}
