# PromocodeEventTransformer

**Файл:** `app/RabbitMQ/Transformers/PromocodeEventTransformer.php`
**Модель:** `App\Models\Promocode`
**Routing key (inbound):** `merchant_promocodes`
**Направление:** Только consume (inbound)

## Описание

Трансформер для обработки входящих событий промокодов из внешних систем. Промокоды привязаны к магазину и имеют период действия.

## Обязательные поля события

```php
['event_id', 'id', 'source', 'title', 'code', 'shop_id', 'shop_source']
```

## Маппинг полей (fromEvent)

| Поле события | Поле модели | Тип | Примечание |
|---|---|---|---|
| `shop_id` + `shop_source` | `store_id` | int\|null | Через metadata → fallback по code |
| `source` | `source` | string | |
| `title` | `title` | string | |
| `code` | `code` | string | |
| — | `type` | string | Всегда `'percent'` |
| `discount_percent` | `value` | int | По умолчанию `0` |
| `valid_from` | `starts_at` | datetime | По умолчанию `now()` |
| `valid_to` | `ends_at` | datetime | По умолчанию `now()->addMonth()` |
| `is_active` | `is_active` | bool | По умолчанию `true` |

> **Примечание:** Тип промокода всегда устанавливается как `percent`. Если в будущем понадобится поддержка фиксированных скидок — требуется доработка.

## Хуки жизненного цикла

### `beforeCreate`
- Проверяет наличие `store_id`
- Если магазин не найден — логирует warning и пропускает событие

### `afterCreate`
- Логирует создание промокода (id, code, store_id)

## Поиск существующей сущности (findExistingEntity)

Поиск по двум полям:
- `store_id` — из трансформированных данных
- `code` — из `eventData['code']`

**Не включает** soft-deleted записи (без `withTrashed`).

> **Примечание:** В отличие от большинства других трансформеров, поиск выполняется по бизнес-ключу `code`, а не по `external_id`.

## Связь с EventSyncMetadata

### Запись metadata для промокода

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `promocodes` |
| `entity_id` | ID созданного/обновлённого Promocode |
| `external_id` | `eventData['id']` (например, `promo-123`) |
| `source` | `eventData['source']` |
| `shop_code` | Определяется пакетом |

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `resolveStoreId()` | `shops` | `resolveEntityId('shops', shopId, shopSource)` | Поиск локального store_id по external_id магазина |

### Порядок зависимости

Для корректной обработки промокода в metadata **должна уже существовать** запись для:
- Магазина (`shops`) — обязательно (иначе промокод пропускается в `beforeCreate`)

### Особенность findExistingEntity

В отличие от большинства трансформеров, `findExistingEntity` ищет промокод по **бизнес-ключу `code`** в таблице `promocodes`, а не через `event_sync_metadata`. Однако пакет всё равно создаёт запись в metadata для дедупликации и версионирования.

## Пример входящего события

```json
{
    "event_id": "uuid",
    "id": "promo-123",
    "source": "crm",
    "title": "Скидка 15% на первый заказ",
    "code": "WELCOME15",
    "shop_id": "shop-1",
    "shop_source": "crm",
    "discount_percent": 15,
    "valid_from": "2024-01-01",
    "valid_to": "2024-12-31",
    "is_active": true
}
```
