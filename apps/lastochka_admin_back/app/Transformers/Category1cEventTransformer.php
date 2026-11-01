<?php

namespace App\Transformers;

use App\Models\Category1c;
use App\Services\ModuleConfigService;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesShopCode;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Трансформер для consumer-side обработки событий справочника 1С-категорий.
 *
 * admin_back ТОЛЬКО потребляет category_1c.{created,updated,deleted} от 1c_parser
 * и пишет в локальную таблицу `categories_1c`. Наружу (toEvent) этот трансформер
 * категорий 1С НЕ публикует — `entities.categories_1c` в config/rabbitmq-events.php
 * не регистрируется. Поэтому toEvent() — заглушка совместимости с интерфейсом.
 *
 * showcase_category_id и linked_at управляются ТОЛЬКО админом из admin_front,
 * никогда не пишутся из event'ов. См. spec §4.2.2.
 *
 * findExistingEntity использует ТОЛЬКО (shop_id, external_id) — без fallback по name.
 * Fallback по человекочитаемому полю склеивает категории-тёзки в одну запись и
 * приводит к каскадному удалению, см. memory/rabbitmq-pitfalls.md секция
 * «findExistingEntity по name/title». В сетевом режиме (см.
 * ModuleConfigService::getCategory1cNetworkAnchorShopId()) shop_id всегда
 * якорного магазина, а не магазина из shop_code события.
 */
class Category1cEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesShopCode;

    protected array $requiredFields = [
        'id',
        'shop_code',
    ];

    /**
     * Маппинг payload → атрибуты модели.
     *
     * Парадигма array_key_exists: только присутствующие в payload ключи попадают
     * в результирующий массив. Это позволяет partial events не затирать поля,
     * установленные локально (положение, активность и т.п.).
     */
    public function fromEvent(array $eventData): array
    {
        $attrs = [];

        $anchorShopId = ModuleConfigService::getCategory1cNetworkAnchorShopId();

        if ($anchorShopId !== null) {
            // Сетевой режим: весь справочник живёт под якорным магазином,
            // shop_code события игнорируется (в конверте остаётся обязательным).
            $attrs['shop_id'] = $anchorShopId;
        } elseif (array_key_exists('shop_code', $eventData) && is_string($eventData['shop_code']) && $eventData['shop_code'] !== '') {
            $attrs['shop_id'] = $this->resolveShopId($eventData['shop_code']);
        }

        if (array_key_exists('id', $eventData)) {
            $attrs['external_id'] = (string) $eventData['id'];
        }

        // 1c_parser шлёт поле `title` (не `name`) — см. CategoryEventTransformer 1c_parser:
        // 'title' => $entity->name. Поддерживаем оба имени для совместимости.
        if (array_key_exists('title', $eventData)) {
            $attrs['name'] = $eventData['title'];
        } elseif (array_key_exists('name', $eventData)) {
            $attrs['name'] = $eventData['name'];
        }

        if (array_key_exists('position', $eventData)) {
            $attrs['position'] = (int) $eventData['position'];
        }

        if (array_key_exists('active', $eventData)) {
            $attrs['is_active'] = (bool) $eventData['active'];
        }

        $attrs['synced_from_1c_at'] = now();

        // showcase_category_id и linked_at — только из admin UI, никогда не из event.
        return $attrs;
    }

    /**
     * Минимальная outbound-реализация на случай если кто-то вызовет toEvent
     * (например через CustomEventPublisher). Сейчас не используется.
     */
    public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
    {
        /** @var Category1c $model */
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $model->updated_at ?? now(),
            'id' => (string) ($model->external_id ?? $model->id),
            'shop_code' => $this->resolveShopCode($relatedModel, $model),
            'source' => config('rabbitmq-events.source'),
            'name' => $model->name,
            'position' => (int) ($model->position ?? 0),
            'active' => (bool) ($model->is_active ?? true),
            'parent_id' => $model->parent ? (string) $model->parent->external_id : null,
        ];
    }

    /**
     * Базовая валидация — наличие id и shop_code.
     * Расширенная валидация (типы, форматы) — на стороне consumer pipeline.
     */
    public function validate(array $eventData): bool
    {
        foreach ($this->requiredFields as $field) {
            if (!array_key_exists($field, $eventData) || $eventData[$field] === null || $eventData[$field] === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Лукап существующей записи по (shop_id, external_id) с учётом soft-delete.
     *
     * NO fallback по name — иначе склеит дубли-тёзки. См. PHPDoc класса.
     *
     * В сетевом режиме (ModuleConfigService::getCategory1cNetworkAnchorShopId()
     * !== null) shop_id всегда якорного магазина — запись ищется по нему для
     * любого shop_code события.
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $shopCode = $eventData['shop_code'] ?? null;
        $externalId = $eventData['id'] ?? null;

        if ($externalId === null || $externalId === '') {
            return null;
        }

        $shopId = ModuleConfigService::getCategory1cNetworkAnchorShopId();

        if ($shopId === null) {
            if (!is_string($shopCode) || $shopCode === '') {
                return null;
            }

            try {
                $shopId = $this->resolveShopId($shopCode);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return Category1c::withTrashed()
            ->where('shop_id', $shopId)
            ->where('external_id', (string) $externalId)
            ->first();
    }

    /**
     * После создания:
     * 1. Разрешить parent_id, если он есть в payload (или запомнить GUID на будущее).
     * 2. Доцепить детей, ждавших эту категорию как родителя (late-binding).
     */
    public function afterCreate(Model $entity, array $eventData): void
    {
        $this->resolveParent($entity, $eventData);
        $this->adoptWaitingChildren($entity);
    }

    /**
     * После обновления:
     * 1. Если запись была soft-deleted (например после category_1c.deleted), а
     *    1С снова прислал .updated — восстановить (admin вернул категорию в 1С).
     * 2. Разрешить parent_id из payload (или запомнить GUID на будущее).
     * 3. Доцепить детей, ждавших эту категорию как родителя (late-binding).
     */
    public function afterUpdate(Model $entity, array $eventData): void
    {
        /** @var Category1c $entity */
        if ($entity->trashed()) {
            $entity->restore();
        }

        $this->resolveParent($entity, $eventData);
        $this->adoptWaitingChildren($entity);
    }

    /**
     * После удаления: ничего дополнительного. Soft-delete делает сам платформенный
     * consumer pipeline; showcase_category_id остаётся для UI badge
     * «источник 1С удалён» (cм. spec §3.5).
     */
    public function afterDelete(Model $entity, array $eventData): void
    {
        // no-op
    }

    /**
     * Разрешить parent_id по external_id parent'а.
     *
     * Поведение:
     *  - parent_id не в payload → ничего не делаем (partial event не должен
     *    затирать существующую привязку).
     *  - parent_id === null → явный сигнал «убрать parent» (категория стала
     *    корневой) → parent_id=null + parent_external_id=null.
     *  - parent_id строка → ВСЕГДА запоминаем GUID в parent_external_id, затем
     *    ищем родителя по (shop_id, external_id). Найден — связываем. Не найден
     *    (1С шлёт дамп не в порядке «родители раньше детей») — текущий parent_id
     *    НЕ трогаем (лучше устаревшая ветка, чем мигание узла в корень);
     *    связь доцепит adoptWaitingChildren, когда родитель приедет.
     *
     * До введения parent_external_id GUID нерезолвнутого родителя терялся —
     * на mendev дамп 2026-07-02 осиротил 1672 категории из 6206.
     */
    private function resolveParent(Model $entity, array $eventData): void
    {
        if (!array_key_exists('parent_id', $eventData)) {
            return;
        }

        // '' страхует от возможного sentinel-формата производителя («корень»
        // как пустая строка вместо null) — иначе '' осел бы в parent_external_id
        // как фиктивный GUID, а старый parent_id остался бы навсегда.
        if ($eventData['parent_id'] === null || $eventData['parent_id'] === '') {
            $entity->update(['parent_id' => null, 'parent_external_id' => null]);
            return;
        }

        $parentExternalId = (string) $eventData['parent_id'];

        /** @var Category1c $entity */
        $parent = Category1c::where('shop_id', $entity->shop_id)
            ->where('external_id', $parentExternalId)
            ->first();

        $attrs = ['parent_external_id' => $parentExternalId];
        if ($parent !== null) {
            $attrs['parent_id'] = $parent->id;
        }

        $entity->update($attrs);
    }

    /**
     * Late-binding: доцепить категории, у которых parent_external_id указывает
     * на только что созданную/обновлённую категорию, а parent_id ещё не связан
     * (или связан со старым родителем после переноса в 1С).
     *
     * Зеркалит паттерн products.pending_category_1c_external_id +
     * Category1cObserver::resolvePending. Bulk-update без Eloquent-событий —
     * categories_1c наружу не публикуется, Category1cObserver на parent_id
     * не реагирует.
     */
    private function adoptWaitingChildren(Model $entity): void
    {
        /** @var Category1c $entity */
        if ($entity->external_id === null || $entity->external_id === '' || $entity->trashed()) {
            return;
        }

        Category1c::where('shop_id', $entity->shop_id)
            ->where('parent_external_id', $entity->external_id)
            ->where('id', '!=', $entity->id)
            ->where(function ($query) use ($entity) {
                $query->whereNull('parent_id')
                    ->orWhere('parent_id', '!=', $entity->id);
            })
            ->update(['parent_id' => $entity->id]);
    }
}
