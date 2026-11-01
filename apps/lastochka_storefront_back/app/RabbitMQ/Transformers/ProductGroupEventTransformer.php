<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Enums\ProductGroupFillType;
use App\Models\ProductGroup;
use App\Models\ProductGroupItem;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class ProductGroupEventTransformer extends BaseEventTransformer
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
    public function toEvent(Model $productGroup, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели ProductGroup.
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
            'description' => $data['description'] ?? null,
            'image_path' => null,
            'show_on_home' => $data['show_on_main'] ?? false,
            'show_on_store_page' => $data['show_on_merchant_page'] ?? false,
            'show_in_catalog' => $data['show_in_merchant_catalog'] ?? false,
            'show_on_search' => $data['show_on_search'] ?? false,
            'is_recommended' => $data['is_recommended'] ?? false,
            'is_cart_page' => $data['is_cart_page'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            // Способ наполнения: без ключа (старые события) и при неизвестном
            // значении — manual, т.е. прежнее поведение группы.
            'fill_type' => ProductGroupFillType::fromValue($data['fill_type'] ?? null)->value,
        ];

        if (array_key_exists('sort_order', $data)) {
            $attributes['position'] = (int) $data['sort_order'];
        }

        // slug: conditional set — payload без ключа (например от источников,
        // которые ещё не публикуют slug) не должен затирать уже
        // сохранённый/бэкфилленный slug. См. CategoryEventTransformer.
        if (array_key_exists('slug', $data) && $data['slug']) {
            $attributes['slug'] = $data['slug'];
        }

        // Conditional set — события от admin_back без ключа (старый код)
        // не затирают локальное значение.
        if (array_key_exists('is_complex_solution', $data)) {
            $attributes['is_complex_solution'] = (bool) $data['is_complex_solution'];
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
            Log::warning('ProductGroup event: store not found', [
                'shop_id' => $eventData['shop_id'],
                'shop_source' => $eventData['shop_source'],
                'product_group_external_id' => $eventData['id'],
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
    public function afterCreate(Model $productGroup, array $eventData): void
    {
        $this->processImage($productGroup, $eventData);
        $this->syncProducts($productGroup, $eventData);
        $this->syncFilterValues($productGroup, $eventData);
    }

    /**
     * После обновления — обновить изображение и товары.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $productGroup, array $eventData): void
    {
        if (isset($eventData['image_url'])) {
            $this->processImage($productGroup, $eventData);
        }

        if (isset($eventData['products'])) {
            $this->syncProducts($productGroup, $eventData);
        }

        if (isset($eventData['filter_values'])) {
            $this->syncFilterValues($productGroup, $eventData);
        }
    }

    /**
     * Скачать и сохранить изображение группы товаров.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processImage(ProductGroup $productGroup, array $eventData): void
    {
        $imageUrl = $eventData['image_url'] ?? null;

        if (! $imageUrl) {
            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'product_groups');
            $productGroup->update(['image_path' => $localPath]);

            Log::info('ProductGroup image downloaded', [
                'product_group_id' => $productGroup->id,
                'image_path' => $localPath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download product group image', [
                'product_group_id' => $productGroup->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Синхронизировать значения фильтров группы товаров.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function syncFilterValues(Model $group, array $eventData): void
    {
        $filterValuesData = $eventData['filter_values'] ?? [];
        if (empty($filterValuesData)) {
            $group->filterValues()->detach();
            return;
        }

        $localIds = [];
        foreach ($filterValuesData as $fv) {
            $filter = \App\Models\ProductGroupFilter::where('name', $fv['filter_name'])->first();
            if (!$filter) {
                continue;
            }
            $value = \App\Models\ProductGroupFilterValue::where('product_group_filter_id', $filter->id)
                ->where('value', $fv['value'])
                ->first();
            if ($value) {
                $localIds[] = $value->id;
            }
        }

        $group->filterValues()->sync($localIds);
    }

    /**
     * Синхронизировать товары группы через ProductGroupItem.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function syncProducts(ProductGroup $productGroup, array $eventData): void
    {
        $products = $eventData['products'] ?? [];

        // Удаляем старые связи
        $productGroup->items()->delete();

        // Автоматическая группа товары не хранит — их подбирает
        // ProductGroupProductsResolver; остатки ручного выбора не нужны.
        if (empty($products) || $productGroup->fillType()->isAutomatic()) {
            return;
        }

        $createdCount = 0;

        foreach ($products as $index => $item) {
            $productId = $this->resolveEntityId(
                'products',
                $item['product_id'] ?? null,
                $item['product_source'] ?? null
            );

            if ($productId) {
                ProductGroupItem::updateOrCreate([
                    'product_group_id' => $productGroup->id,
                    'product_id' => $productId,
                ], [
                    'position' => $index,
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ]);
                $createdCount++;
            }
        }

        Log::info('ProductGroup products synced', [
            'product_group_id' => $productGroup->id,
            'products_count' => $createdCount,
        ]);
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
     * Поиск существующей группы по external_id и source в том же магазине.
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

        return ProductGroup::withTrashed()
            ->where('store_id', $storeId)
            ->where('external_id', $externalId)
            ->where('source', $source)
            ->first();
    }
}
