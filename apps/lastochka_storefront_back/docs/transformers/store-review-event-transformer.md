# StoreReviewEventTransformer

**Файл:** `app/RabbitMQ/Transformers/StoreReviewEventTransformer.php`
**Модель:** `App\Models\StoreReview`
**Routing key (outbound):** `store_reviews`
**Routing key (inbound):** `store_reviews`
**Направление:** Двусторонний (publish + consume)

## Описание

Двусторонний трансформер для синхронизации отзывов на магазины. Архитектурно аналогичен `ProductReviewEventTransformer`, но работает с моделью `StoreReview` и пересчитывает рейтинг магазина (а не товара).

## Обязательные поля события

```php
['event_id', 'id', 'source', 'shop_id', 'shop_source', 'rating']
```

## Условие публикации (outbound)

Используется `ReviewHasUserCondition` — событие публикуется только для отзывов с привязанным пользователем.

## Маппинг полей

### toEvent (Model → RabbitMQ)

| Поле модели | Поле события | Примечание |
|---|---|---|
| `id` | `id` | String |
| — | `source` | Из конфига |
| `store_id` | `shop_id` | External_id магазина из EventSyncMetadata |
| — | `shop_source` | Source из metadata |
| `user_id` | `user_id` | String |
| — | `user_source` | Из конфига |
| `rating` | `rating` | int |
| `description` | `description` | string\|null |
| `author_name` | `author_name` | string\|null |
| `author_phone` | `author_phone` | string\|null |
| `is_published` | `is_published` | bool |
| `created_at` | `created_at` | ISO 8601 |

### fromEvent (RabbitMQ → Model)

| Поле события | Поле модели | Примечание |
|---|---|---|
| `shop_id` + `shop_source` | `store_id` | Через `resolveStoreIdByExternalId` |
| `source` | `source` | |
| — | `user_id` | Всегда `null` для внешних отзывов |
| `rating` | `rating` | int |
| `description` | `description` | string\|null |
| `author_name` | `author_name` | string\|null |
| `author_phone` | `author_phone` | string\|null |
| `is_published` | `is_published` | bool, по умолчанию `false` |
| — | `published_at` | `now()` если `is_published = true` |

## Хуки жизненного цикла

### `beforeCreate`
- Проверяет наличие `store_id`
- Если магазин не найден — логирует warning и пропускает событие

### `afterCreate`
- Пересчитывает рейтинг магазина (**только если отзыв опубликован**)
- Логирует создание

### `beforeUpdate`
- **Исключает `user_id` из обновления** — защита привязки к пользователю

### `afterUpdate`
- Пересчитывает рейтинг магазина (безусловно)

### `afterDelete`
- Пересчитывает рейтинг магазина

## Пересчёт рейтинга (recalculateStoreRating)

```sql
-- В транзакции с pessimistic lock на Store
SELECT id FROM stores WHERE id = ? FOR UPDATE;

SELECT AVG(rating) as avg_rating, COUNT(*) as count
FROM store_reviews
WHERE store_id = ? AND is_published = true;

UPDATE stores SET rating = ?, reviews_count = ? WHERE id = ?;
```

**Механика:**
- Pessimistic lock (`lockForUpdate()`) для предотвращения race condition
- Учитываются только опубликованные отзывы
- Рейтинг округляется до 1 знака после запятой

## Сравнение с ProductReviewEventTransformer

| Аспект | StoreReview | ProductReview |
|---|---|---|
| Целевая модель | StoreReview | ProductReview |
| Рейтинг пересчитывается для | Store | Product |
| Доп. поле в обязательных | — | `product_id`, `product_source` |
| beforeCreate проверяет | store_id | product_id + store_id |

## Связь с EventSyncMetadata

### Запись metadata для отзыва на магазин

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `store_reviews` |
| `entity_id` | ID созданного/обновлённого StoreReview |
| `external_id` | `eventData['id']` (или `review->id` при outbound) |
| `source` | `eventData['source']` (или из конфига при outbound) |
| `shop_code` | Определяется пакетом |

### Прямое использование EventSyncMetadata (outbound)

Метод `toEvent()` **напрямую** обращается к `EventSyncMetadata` для получения external_id магазина:

```php
$storeMeta = EventSyncMetadata::where('entity_type', 'shops')
    ->where('entity_id', $review->store_id)->first();
```

Это позволяет отправить отзыв в CRM с указанием магазина по **его внешнему ID**.

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `toEvent()` | `shops` | Прямой запрос к `EventSyncMetadata` | Получение external_id магазина для исходящего события |
| `fromEvent()` | `shops` | `resolveStoreIdByExternalId(shopId, shopSource)` | Поиск локального store_id (metadata → fallback по code) |

### Fallback при отсутствии metadata (outbound)

Если для магазина нет записи в `event_sync_metadata`:
- `shop_id` = `(string) $review->store_id` (локальный ID)
- `shop_source` = значение из конфига `rabbitmq-events.source`

### Порядок зависимости (inbound)

Для корректной обработки входящего отзыва в metadata **должна уже существовать** запись для:
- Магазина (`shops`) — обязательно (иначе отзыв пропускается в `beforeCreate`)

## Пример исходящего события

```json
{
    "event_id": "uuid",
    "event_timestamp": "2024-01-20T12:00:00+03:00",
    "event_source": "storefront",
    "id": "10",
    "source": "storefront",
    "shop_id": "ext-shop-1",
    "shop_source": "crm",
    "user_id": "42",
    "user_source": "storefront",
    "rating": 4,
    "description": "Хороший магазин, быстрая доставка",
    "author_name": "Иван П.",
    "author_phone": "+79991234567",
    "is_published": true,
    "created_at": "2024-01-20T12:00:00+03:00"
}
```
