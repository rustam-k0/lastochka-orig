<?php

namespace App\Transformers;

use App\Models\Status;
use Comrades\RabbitMQEvents\Concerns\DownloadsExternalImages;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ResolvesShopCode;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\ShopCategory;

class CategoryEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use DownloadsExternalImages;
    use ResolvesExternalReferences;
    use ResolvesShopCode;

    protected array $requiredFields = [
        'event_id',
        'id',
        'shop_code',
        'source',
        'title',
    ];

    /**
     * Конфигурация внешних ссылок
     * parent_id + parent_source → локальный parent_id
     */
    protected array $externalReferences = [
        'parent_id' => [
            'type' => \App\Models\Category::class,
            'source_field' => 'parent_source',
            'id_field' => 'parent_id',
            'nullable' => true,
        ],
    ];

    public function toEvent(Model $category, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $category->updated_at ?? now(),
            'id' => (string) $category->id,
            'shop_code' => $this->resolveShopCode($relatedModel, $category),
            'source' => config('rabbitmq-events.source'),
            'title' => $category->title,
            // link генерируется автоматически из title в Category::boot()
            // (creating/updating) — публикуем его потребителям как slug.
            'slug' => $category->link,
            'active' => (bool) ($category->is_active ?? true),
            'created_at' => $category->created_at ?? null,
            'updated_at' => $category->updated_at ?? null,
            'deleted_at' => $category->deleted_at ?? null,
            'image_url' => $this->getCategoryImageUrl($category),
            'background_image_url' => $this->getCategoryBackgroundImageUrl($category),
            'is_perishable' => (bool) ($category->is_perishable ?? false),
            'show_on_main' => (bool) ($category->show_on_main ?? false),
            'position' => (int) ($category->position ?? 0),
            'custom_checkboxes' => json_decode($category->custom_checkboxes ?: '{}', true) ?: [],
            'tag_text' => $category->tag_text,
            'tag_color' => $category->tag_color,
        ];

        // Добавить parent_id + parent_source
        return $this->addExternalReferencesToEvent($category, $eventData);
    }

    /**
     * Payload для кастомного события shop_detached.
     *
     * Форсит id=локальный admin primary key и source=текущий сервис, чтобы
     * storefront (и другие потребители) мог резолвить metadata по
     * (entity_type='categories', external_id=$category->id, source='app').
     *
     * Без этого override CustomEventPublisher подставляет external_id из
     * event_sync_metadata — а он может быть от предыдущего источника
     * синхронизации (1С и т.п.), которого storefront никогда не видел,
     * и consumer не найдёт нужную category_store связь.
     */
    public function toCustomEvent(Model $category, string $eventType, array $customPayload, ?string $eventId = null): ?array
    {
        if ($eventType !== 'shop_detached') {
            return null;
        }

        return array_merge([
            'id' => (string) $category->id,
            'source' => config('rabbitmq-events.source'),
            'title' => $category->title,
        ], $customPayload);
    }

    public function fromEvent(array $data): array
    {
        $title = $data['title'] ?? 'Unknown';

        return [
            'title' => $title,
            // link нужно генерировать явно, т.к. withoutEvents() отключает boot hooks
            'link' => \App\Models\Category::getUniqueLink($title),
            'is_cooking' => 0,
            'is_perishable' => $data['is_perishable'] ?? false,
            'show_on_main' => $data['show_on_main'] ?? false,
            // parent_id будет разрешен в afterCreate
        ];
    }

    /**
     * Fallback-матчинг уже существующей категории по title — когда в
     * event_sync_metadata ещё нет связки (source, external_id) → entity_id,
     * но локально в admin_back админ уже создал одноимённую категорию.
     *
     * Без этого первый resync из 1С задваивал категории: пакет не находил
     * metadata (её не было) → дефолтный findExistingEntity=null → создавалась
     * новая запись рядом с существующей. См. Platrum #9658, ALADDIN-9822
     * и memory rabbitmq-pitfalls.md (секция «findExistingEntity по name/title»).
     *
     * Матчинг:
     *  - case-insensitive, с trim (чтобы «Кулинария » и «кулинария» совпали)
     *  - scoped по shop_categories.shop_id — разные магазины в мультивендоре
     *    могут иметь одноимённые категории (свою «Прочее» и т.п.), сливать
     *    их нельзя.
     *  - без shop_code в event или при нерезолвимом магазине — возвращаем
     *    null (пусть пакет создаёт новую запись), чтобы не слинковать
     *    категории из разных магазинов в одну.
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $title = trim((string) ($eventData['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $shopCode = $eventData['shop_code'] ?? null;
        if (!is_string($shopCode) || $shopCode === '') {
            return null;
        }

        try {
            $shopId = $this->resolveShopId($shopCode);
        } catch (\Throwable $e) {
            return null;
        }

        return \App\Models\Category::query()
            ->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower($title)])
            ->whereHas('shopCategories', fn ($q) => $q->where('shop_id', $shopId))
            ->first();
    }

    /**
     * После создания категории:
     * 1. Разрешить parent_id (через external references)
     * 2. Создать связь с магазином (ShopCategory)
     * 3. Скачать изображение
     * 4. Проверить pending refs (может кто-то ждал эту категорию)
     */
    public function afterCreate(Model $category, array $eventData): void
    {
        // 1. Разрешить parent_id
        $this->resolveAndUpdateReferences($category, $eventData);

        // 2. Создать связь с магазином (ShopCategory)
        $this->processShopCategory($category, $eventData);

        // 3. Скачать изображение
        $this->downloadExternalImage(
            $category,
            $eventData,
            'image_url',
            'siteCatalogImg',
            Status::CODE_SITE_CATALOG_IMG,
            'categories',
            $category->title
        );

        // Фон (если событие пришло с background_image_url — напр. от другого форка)
        $this->downloadExternalImage(
            $category,
            $eventData,
            'background_image_url',
            'backgroundImg',
            Status::CODE_BACKGROUND_IMG,
            'categories',
            $category->title
        );

        // 4. Разрешить pending refs (child categories ждущие эту категорию)
        $this->resolvePendingReferencesForEntity($category);
    }

    /**
     * После обновления категории:
     * 1. Обновить parent_id
     * 2. Создать/обновить связь с магазином (ShopCategory)
     */
    public function afterUpdate(Model $category, array $eventData): void
    {
        // 1. Разрешить parent_id
        $this->resolveAndUpdateReferences($category, $eventData);

        // 2. Создать/обновить связь с магазином (ShopCategory)
        $this->processShopCategory($category, $eventData);
    }

    /**
     * Создать/обновить связь категории с магазином
     *
     * Связь хранится в таблице shop_categories (shop_id, category_id)
     */
    protected function processShopCategory(Model $category, array $eventData): void
    {
        $shopCode = $eventData['shop_code'] ?? config('rabbitmq-events.shop_code');
        $shopId = $this->resolveShopId($shopCode);

        try {
            // Используем firstOrCreate для идемпотентности
            ShopCategory::firstOrCreate([
                'shop_id' => $shopId,
                'category_id' => $category->id,
            ]);

            Log::debug('ShopCategory processed', [
                'category_id' => $category->id,
                'shop_id' => $shopId,
                'shop_code' => $shopCode,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate key (race condition) - связь уже существует
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                Log::debug('ShopCategory already exists (race condition)', [
                    'category_id' => $category->id,
                    'shop_id' => $shopId,
                ]);
                return;
            }

            Log::error('Failed to create ShopCategory', [
                'category_id' => $category->id,
                'shop_id' => $shopId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Получить URL главного изображения категории для витрины.
     *
     * Fallback по слотам join_img (head_id), в порядке приоритета:
     *  1. siteCatalogImg (102, CODE_SITE_CATALOG_IMG) — актуальный слот
     *     для витрины, куда `CategoryUpdate` дублирует картинку с 2024+.
     *  2. siteSquareImg (103, CODE_SITE_SQUARE_IMG) — исторический слот:
     *     до правки `CategoryUpdate::__invoke()` фото категории заливали
     *     только сюда, и слот 102 оставался пустым. Категории, созданные
     *     до этой правки, без fallback уезжают в витрину с пустым
     *     `image_url`, и мобильное приложение молча оставляет картинку
     *     прежней (пустой), см. ALD-131.
     *
     * Головная картинка (100, CODE_HEAD_IMG) в fallback не участвует:
     * для Category такого слота вообще не существует — ни связи в модели,
     * ни поля в `CategoryUpdate`, слот 100 используется только у Product.
     */
    protected function getCategoryImageUrl(\App\Models\Category $category): ?string
    {
        if ($category->siteCatalogImg && $category->siteCatalogImg->path) {
            $url = PathNormalizer::url($category->siteCatalogImg->path);
            Log::debug('getCategoryImageUrl: используем siteCatalogImg (102)', [
                'category_id' => $category->id,
                'url' => $url,
            ]);
            return $url;
        }

        if ($category->siteSquareImg && $category->siteSquareImg->path) {
            $url = PathNormalizer::url($category->siteSquareImg->path);
            Log::debug('getCategoryImageUrl: fallback на siteSquareImg (103)', [
                'category_id' => $category->id,
                'url' => $url,
            ]);
            return $url;
        }

        Log::debug('getCategoryImageUrl: изображение не найдено', ['category_id' => $category->id]);
        return null;
    }

    /**
     * URL фонового изображения категории (для RabbitMQ payload).
     *
     * Fallback по слотам здесь не нужен: у фона (109, CODE_BACKGROUND_IMG)
     * нет исторического дублирующего слота вроде пары 102/103 у основной
     * картинки — `CategoryUpdate::__invoke()` всегда писал `background_img`
     * только в 109, второго "старого" слота для фона никогда не было.
     */
    protected function getCategoryBackgroundImageUrl(\App\Models\Category $category): ?string
    {
        if ($category->backgroundImg && $category->backgroundImg->path) {
            return PathNormalizer::url($category->backgroundImg->path);
        }

        return null;
    }
}
