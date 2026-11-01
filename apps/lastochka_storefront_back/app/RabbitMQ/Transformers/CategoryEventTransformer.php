<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Category;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class CategoryEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'shop_code', 'source', 'title'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $category, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Category.
     *
     * ВАЖНО (ALD-133): 'image_path'/'background_image_path' сюда намеренно
     * НЕ включены. До фикса они безусловно обнулялись здесь на КАЖДОЕ
     * событие (в т.ч. update, где картинка не менялась), а afterUpdate()
     * условно перекачивал файл и перезаписывал путь заново. Если
     * processImage()/processBackgroundImage() падали на скачивании
     * (таймаут/сеть/5xx у отдающего файл сервиса), путь оставался null
     * навсегда — до следующего успешного события по этой же категории,
     * которое могло не прийти месяцами. Именно так пропало фото категории
     * «Соки / Морс» (admin id 20, витрина id 620): payload с валидным
     * image_url ушёл 2026-07-21, скачивание один раз не удалось, и без
     * повторных правок категории путь так и остался пустым. Теперь эти два
     * поля — исключительно во владении processImage()/processBackgroundImage()
     * (см. afterCreate/afterUpdate): создание оставляет их NULL по
     * умолчанию (колонки nullable), а обновление трогает их только когда
     * ключ *_url реально пришёл в событии, и не трогает вовсе при сбое
     * скачивания — сохраняя прежнее значение.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $attributes = [
            'source' => $data['source'],
            'parent_id' => null,
            'name' => $data['title'],
            'is_active' => $data['active'] ?? true,
            'is_perishable' => $data['is_perishable'] ?? false,
            'show_on_main' => $data['show_on_main'] ?? false,
            'position' => $data['position'] ?? 0,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
        ];

        // Динамические чекбоксы: conditional set — события без ключа
        // не затирают локальные значения.
        if (array_key_exists('custom_checkboxes', $data)) {
            $attributes['custom_checkboxes'] = is_array($data['custom_checkboxes'])
                ? array_map(fn ($v) => (bool) $v, $data['custom_checkboxes'])
                : [];
        }

        // slug: conditional set — не все источники в шине category.* (например
        // 1С) присылают его; payload без ключа не должен затирать уже
        // сохранённый/бэкфилленный slug.
        if (array_key_exists('slug', $data) && $data['slug']) {
            $attributes['slug'] = $data['slug'];
        }

        // Тег категории: conditional set — в шину category.* публикует не только
        // админка; payload без этих ключей не должен затирать сохранённый тег.
        if (array_key_exists('tag_text', $data)) {
            $attributes['tag_text'] = $data['tag_text'];
        }
        if (array_key_exists('tag_color', $data)) {
            $attributes['tag_color'] = $data['tag_color'];
        }

        return $attributes;
    }

    /**
     * После создания — привязать к магазину, скачать изображение и связать с родителем.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $category, array $eventData): void
    {
        $this->attachToStore($category, $eventData);
        $this->processImage($category, $eventData);
        $this->processBackgroundImage($category, $eventData);
        $this->linkParent($category, $eventData);
    }

    /**
     * После обновления — привязать к магазину, обновить изображение и родителя.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $category, array $eventData): void
    {
        $this->attachToStore($category, $eventData);

        // conditional set (array_key_exists, не isset!): ключ image_url со
        // значением null — легитимный сигнал «фото сняли в админке», его
        // нельзя терять из-за isset()===false на null. Событие без самого
        // ключа (источник без этого поля) не должно трогать image_path —
        // см. processImage().
        if (array_key_exists('image_url', $eventData)) {
            $this->processImage($category, $eventData);
        }

        if (array_key_exists('background_image_url', $eventData)) {
            $this->processBackgroundImage($category, $eventData);
        }

        $this->linkParent($category, $eventData);
    }

    /**
     * Привязать категорию к магазину через pivot.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function attachToStore(Category $category, array $eventData): void
    {
        $storeId = $this->resolveOrCreateStoreByCode($eventData['shop_code'], $eventData['source']);

        if ($storeId === null) {
            Log::warning('Category attach skipped: shop_code has no matching store', [
                'category_id' => $category->id,
                'shop_code' => $eventData['shop_code'],
                'source' => $eventData['source'],
            ]);

            return;
        }

        $category->stores()->syncWithoutDetaching([
            $storeId => [
                // Порядок категории приходит из админки в payload (position).
                // Пишем его в pivot category_store.position — именно по нему
                // сортирует выдачу scopeOrderedForStore. Fallback на локальную
                // categories.position (её только что выставил fromEvent), затем 0.
                'position' => $eventData['position'] ?? $category->position ?? 0,
                'is_active' => $eventData['active'] ?? true,
            ],
        ]);

        Log::info('Category attached to store', [
            'category_id' => $category->id,
            'store_id' => $storeId,
            'shop_code' => $eventData['shop_code'],
        ]);
    }

    /**
     * Скачать изображение категории.
     *
     * ALD-133: image_url=null/отсутствие значения — явный сигнал «фото
     * сняли», снимаем сохранённый путь (симметрично processBackgroundImage).
     * Если URL есть, но скачивание падает (сеть/таймаут/5xx) — НЕ трогаем
     * image_path: категория остаётся с прежней (последней успешно скачанной)
     * картинкой вместо пустой. Раньше вызывающая сторона (fromEvent) заранее
     * обнуляла image_path на каждое событие, и неудачное скачивание здесь
     * оставляло его null навсегда, без ретрая — ровно так админ-категория 20
     * «Соки / Морс» осталась без фото на витрине после разового сбоя
     * скачивания 2026-07-21.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processImage(Category $category, array $eventData): void
    {
        $imageUrl = $eventData['image_url'] ?? null;

        if (! $imageUrl) {
            if ($category->image_path !== null) {
                $category->update(['image_path' => null]);
            }

            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'categories');
            $category->update(['image_path' => $localPath]);

            Log::info('Category image downloaded', [
                'category_id' => $category->id,
                'image_path' => $localPath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download category image, keeping previous image_path', [
                'category_id' => $category->id,
                'image_url' => $imageUrl,
                'previous_image_path' => $category->image_path,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Скачать/обновить/снять фоновое изображение категории.
     *
     * Ключ background_image_url присутствует во всех событиях из admin_back
     * (null, если фон не задан) — поэтому afterUpdate обрабатывает по
     * array_key_exists: непустой URL → скачать, null → снять текущий фон.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processBackgroundImage(Category $category, array $eventData): void
    {
        $imageUrl = $eventData['background_image_url'] ?? null;

        if (! $imageUrl) {
            if ($category->background_image_path !== null) {
                $category->update(['background_image_path' => null]);
            }

            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'categories/backgrounds');
            $category->update(['background_image_path' => $localPath]);

            Log::info('Category background image downloaded', [
                'category_id' => $category->id,
                'background_image_path' => $localPath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download category background image', [
                'category_id' => $category->id,
                'background_image_url' => $imageUrl,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Связать с родительской категорией.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function linkParent(Category $category, array $eventData): void
    {
        $parentId = $eventData['parent_id'] ?? null;
        $parentSource = $eventData['parent_source'] ?? null;

        if (! $parentId || ! $parentSource) {
            return;
        }

        $parentEntityId = $this->resolveEntityId(
            'categories',
            $parentId,
            $parentSource,
            $eventData['shop_code']
        );

        if ($parentEntityId) {
            $category->update(['parent_id' => $parentEntityId]);

            Log::info('Category linked to parent', [
                'category_id' => $category->id,
                'parent_id' => $parentEntityId,
            ]);

            return;
        }

        $this->createPendingReference(
            $category,
            'parent_id',
            'categories',
            $parentSource,
            $parentId,
            $eventData['shop_code']
        );
    }

    /**
     * После удаления — отвязать категорию от всех магазинов.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterDelete(Model $category, array $eventData): void
    {
        $category->stores()->detach();
    }

    /**
     * Отвязать категорию от конкретного магазина (не удаляя саму категорию).
     * Триггерится custom event `category.shop_detached` из admin_back,
     * когда в shops_categories pivot убирают связь.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function processShopDetachedEvent(Model $category, array $eventData): void
    {
        $shopCode = $eventData['shop_code'] ?? null;

        if (! $shopCode) {
            Log::warning('shop_detached event without shop_code, skipping', [
                'category_id' => $category->id,
                'event_id' => $eventData['event_id'] ?? null,
            ]);

            return;
        }

        $storeId = $this->resolveStoreIdByCode($shopCode);

        if (! $storeId) {
            Log::info('shop_detached: store not found by code, nothing to detach', [
                'category_id' => $category->id,
                'shop_code' => $shopCode,
            ]);

            return;
        }

        $detached = $category->stores()->detach($storeId);

        Log::info('Category detached from store', [
            'category_id' => $category->id,
            'store_id' => $storeId,
            'shop_code' => $shopCode,
            'pivot_rows_removed' => $detached,
        ]);
    }

    /**
     * Поиск существующей категории.
     *
     * Порядок:
     *   1) Primary — по `event_sync_metadata (source, external_id)`.
     *      Это ловит повторные апдейты/деливери той же admin-категории
     *      и multi-shop кейс (один admin-id × N магазинов): metadata
     *      для categories создаётся без shop_code (global_scope), поэтому
     *      второе событие с тем же external_id и другим shop_code
     *      резолвится в ту же локальную запись.
     *   2) Fallback — по `name`, только если у найденной по имени категории
     *      ещё нет metadata-пары с другим external_id в том же source.
     *      Иначе это cross-resolve (разные admin-категории с одинаковым
     *      title → ALADDIN-9822); возвращаем null, чтобы пакет создал
     *      новую локальную категорию и записал собственную metadata.
     *
     * Soft-deleted найденные записи восстанавливаются, как и раньше.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $externalId = isset($eventData['id']) ? (string) $eventData['id'] : null;
        $source = $eventData['source'] ?? null;

        if ($externalId !== null && $source) {
            $entityId = $this->resolveEntityId('categories', $externalId, $source);

            if ($entityId) {
                $category = Category::withTrashed()->find($entityId);

                if ($category) {
                    if ($category->trashed()) {
                        $category->restore();
                    }

                    return $category;
                }
            }
        }

        $name = $eventData['title'] ?? null;
        if (! $name) {
            return null;
        }

        $category = Category::withTrashed()
            ->where('name', $name)
            ->first();

        if (! $category) {
            return null;
        }

        if ($source && $externalId !== null) {
            $conflictExists = EventSyncMetadata::where('entity_type', 'categories')
                ->where('entity_id', $category->id)
                ->where('source', $source)
                ->where('external_id', '!=', $externalId)
                ->exists();

            if ($conflictExists) {
                Log::info('Category name-fallback skipped: metadata already bound to different external_id', [
                    'category_id' => $category->id,
                    'name' => $name,
                    'incoming_external_id' => $externalId,
                    'source' => $source,
                ]);

                return null;
            }
        }

        if ($category->trashed()) {
            $category->restore();
        }

        return $category;
    }
}
