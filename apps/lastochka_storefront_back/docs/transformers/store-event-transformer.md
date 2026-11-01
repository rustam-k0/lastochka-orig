# StoreEventTransformer

**Файл:** `app/RabbitMQ/Transformers/StoreEventTransformer.php`
**Модель:** `App\Models\Store`
**Routing key (inbound):** `shops`
**Направление:** Только consume (inbound)

## Описание

Трансформер для обработки входящих событий магазинов. Помимо базовых атрибутов, синхронизирует расписание работы магазина через связанную модель `StoreSchedule`.

## Обязательные поля события

```php
['event_id', 'id', 'shop_code', 'source']
```

## Маппинг полей (fromEvent)

| Поле события | Поле модели | Тип | Примечание |
|---|---|---|---|
| `shop_code` | `code` | string | Уникальный код магазина |
| `source` | `source` | string | |
| `title` | `name` | string\|null | |
| `active` | `is_active` | bool | По умолчанию `true` |
| `type` | `type` | string | `shop`\|`restaurant`. Зеркалит `shops.type_id` курьерки (`Type::SHOP_TYPE_STORE`\|`SHOP_TYPE_CAFE`). Любое значение кроме `restaurant` (включая отсутствие поля) → `shop` |
| `latitude` | `latitude` | float\|null | |
| `longitude` | `longitude` | float\|null | |
| `slot_time` | `slot_duration` | int\|null | Длительность слота доставки |
| `open_24_7` | `is_open_24_7` | bool | По умолчанию `false` |

## Хуки жизненного цикла

### `afterCreate`
1. **syncSchedule** — синхронизация расписания работы
2. **processImage** — скачивание изображения в папку `stores/`

### `afterUpdate`
1. **syncSchedule** — обновление расписания (если `schedule` присутствует)
2. **processImage** — обновление изображения (если `image_url` присутствует)

## Синхронизация расписания (syncSchedule)

Атомарная операция через DB::transaction:
1. Удаление всего старого расписания (`$store->schedules()->delete()`)
2. Создание новых записей `StoreSchedule` для каждого дня

### Маппинг дней недели

| Ключ события | day_of_week в БД |
|---|---|
| `monday` | 1 |
| `tuesday` | 2 |
| `wednesday` | 3 |
| `thursday` | 4 |
| `friday` | 5 |
| `saturday` | 6 |
| `sunday` | 7 |

### Обработка значений

- **Рабочий день:** `{"start": "09:00", "end": "21:00"}` → `StoreSchedule` с `opens_at` и `closes_at`
- **Выходной:** `null` → `StoreSchedule` с `opens_at = null` и `closes_at = null`
- **Неизвестный день:** Ключ не из DAY_MAP — пропускается

## Поиск существующей сущности (findExistingEntity)

Поиск по полю `code` в таблице `stores`. Включает soft-deleted записи (`withTrashed`).

> **Примечание:** Магазин идентифицируется по `shop_code`, а не по `external_id`, так как `code` — уникальный бизнес-идентификатор.

## Связь с EventSyncMetadata

### Запись metadata для магазина

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `shops` |
| `entity_id` | ID созданного/обновлённого Store |
| `external_id` | `eventData['id']` (например, `store-123`) |
| `source` | `eventData['source']` |
| `shop_code` | `eventData['shop_code']` |

### Прямое использование EventSyncMetadata

Трансформер **не вызывает** `resolveEntityId()` и не обращается к `EventSyncMetadata` напрямую. Поиск существующего магазина выполняется по `code` (бизнес-ключ).

### Роль metadata при синхронизации

Хотя трансформер не читает metadata явно, записи `entity_type='shops'` играют **ключевую роль** для всей системы:
- **Дедупликация** — `last_event_id` предотвращает повторную обработку
- **Версионирование** — `last_event_timestamp` игнорирует устаревшие события

### Кто зависит от metadata магазинов

Записи `entity_type='shops'` — одни из самых востребованных. Их используют:
- **BannerEventTransformer** — `resolveStoreId()` ищет магазин для привязки баннера
- **ProductGroupEventTransformer** — `resolveStoreId()` ищет магазин для привязки группы товаров
- **PromocodeEventTransformer** — `resolveStoreId()` ищет магазин для привязки промокода
- **PromotionEventTransformer** — `resolveStoreIdByExternalId()` ищет магазин для привязки акции
- **ProductReviewEventTransformer** — `fromEvent()` и `toEvent()` используют для маппинга store_id
- **StoreReviewEventTransformer** — `fromEvent()` и `toEvent()` используют для маппинга store_id
- **StoryEventTransformer** — `linkReferences()` ищет магазин для ссылки истории

> **Важно:** Магазины рекомендуется синхронизировать **первыми**, так как большинство других сущностей зависят от их metadata.

## Пример входящего события

```json
{
    "event_id": "uuid",
    "id": "store-123",
    "shop_code": "SHOP1",
    "source": "crm",
    "title": "Магазин на Ленина",
    "active": true,
    "latitude": 55.7558,
    "longitude": 37.6173,
    "slot_time": 60,
    "open_24_7": false,
    "image_url": "https://example.com/store.jpg",
    "schedule": {
        "monday": {"start": "08:00", "end": "22:00"},
        "tuesday": {"start": "08:00", "end": "22:00"},
        "wednesday": {"start": "08:00", "end": "22:00"},
        "thursday": {"start": "08:00", "end": "22:00"},
        "friday": {"start": "08:00", "end": "22:00"},
        "saturday": {"start": "09:00", "end": "21:00"},
        "sunday": null
    }
}
```
