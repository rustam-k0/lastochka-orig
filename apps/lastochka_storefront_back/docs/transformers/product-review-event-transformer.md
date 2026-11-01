# ProductReviewEventTransformer

**Файл:** `app/RabbitMQ/Transformers/ProductReviewEventTransformer.php`
**Модель:** `App\Models\ProductReview`
**Routing key (outbound):** `product_reviews`
**Routing key (inbound):** `product_reviews`
**Направление:** Двусторонний (publish + consume)

## Описание

Двусторонний трансформер для синхронизации отзывов на товары. Отправляет отзывы пользователей в CRM и принимает отзывы из внешних систем (без привязки к user_id). При создании/обновлении/удалении — пересчитывает рейтинг товара с pessimistic lock.

## Обязательные поля события

```php
['event_id', 'id', 'source', 'product_id', 'product_source', 'shop_id', 'shop_source', 'rating']
```

## Условие публикации (outbound)

Используется `ReviewHasUserCondition` — событие публикуется только для отзывов с привязанным пользователем.

## Маппинг полей

### toEvent (Model → RabbitMQ)

| Поле модели | Поле события | Примечание |
|---|---|---|
| `id` | `id` | String |
| — | `source` | Из конфига |
| `product_id` | `product_id` | External_id из EventSyncMetadata (или локальный id) |
| — | `product_source` | Source из metadata (или из конфига) |
| `store_id` | `shop_id` | External_id магазина из metadata |
| — | `shop_source` | Source из metadata |
| `user_id` | `user_id` | String |
| `rating` | `rating` | int |
| `description` | `description` | string\|null |
| `author_name` | `author_name` | string\|null |
| `author_phone` | `author_phone` | string\|null |
| `is_published` | `is_published` | bool |
| `created_at` | `created_at` | ISO 8601 |

### fromEvent (RabbitMQ → Model)

| Поле события | Поле модели | Примечание |
|---|---|---|
| `product_id` + `product_source` | `product_id` | Через `resolveEntityId('products', ...)` |
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
- Проверяет наличие `product_id` — если товар не найден, логирует warning и пропускает
- Проверяет наличие `store_id` — если магазин не найден, логирует warning и пропускает

### `afterCreate`
- Пересчитывает рейтинг товара (**только если отзыв опубликован**)
- Логирует создание

### `beforeUpdate`
- **Исключает `user_id` из обновления** — чтобы не сбросить привязку к пользователю при модерации отзыва в CRM

### `afterUpdate`
- Пересчитывает рейтинг товара (безусловно)

### `afterDelete`
- Пересчитывает рейтинг товара

## Пересчёт рейтинга (recalculateProductRating)

```sql
-- В транзакции с pessimistic lock на Product
SELECT id FROM products WHERE id = ? FOR UPDATE;

SELECT AVG(rating) as avg_rating, COUNT(*) as count
FROM product_reviews
WHERE product_id = ? AND is_published = true;

UPDATE products SET rating = ?, reviews_count = ? WHERE id = ?;
```

**Важно:**
- Используется `lockForUpdate()` для предотвращения race condition при параллельных обновлениях
- Учитываются только опубликованные отзывы (`is_published = true`)
- Рейтинг округляется до 1 знака после запятой

## Связь с EventSyncMetadata

### Запись metadata для отзыва на товар

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `product_reviews` |
| `entity_id` | ID созданного/обновлённого ProductReview |
| `external_id` | `eventData['id']` (или `review->id` при outbound) |
| `source` | `eventData['source']` (или из конфига при outbound) |
| `shop_code` | Определяется пакетом |

### Прямое использование EventSyncMetadata (outbound)

Метод `toEvent()` **напрямую** обращается к `EventSyncMetadata` для получения external_id товара и магазина:

```php
$productMeta = EventSyncMetadata::where('entity_type', 'products')
    ->where('entity_id', $review->product_id)->first();

$storeMeta = EventSyncMetadata::where('entity_type', 'shops')
    ->where('entity_id', $review->store_id)->first();
```

Это позволяет отправить отзыв в CRM с указанием товара и магазина по **их внешним ID**, а не по локальным.

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `toEvent()` | `products` | Прямой запрос к `EventSyncMetadata` | Получение external_id товара для исходящего события |
| `toEvent()` | `shops` | Прямой запрос к `EventSyncMetadata` | Получение external_id магазина для исходящего события |
| `fromEvent()` | `products` | `resolveEntityId('products', productId, productSource)` | Поиск локального product_id по external_id из входящего события |
| `fromEvent()` | `shops` | `resolveStoreIdByExternalId(shopId, shopSource)` | Поиск локального store_id (metadata → fallback по code) |

### Fallback при отсутствии metadata (outbound)

Если для товара или магазина нет записи в `event_sync_metadata`:
- `product_id` / `shop_id` = `(string) $review->product_id` / `(string) $review->store_id` (локальные ID)
- `product_source` / `shop_source` = значение из конфига `rabbitmq-events.source`

### Порядок зависимости (inbound)

Для корректной обработки входящего отзыва в metadata **должны уже существовать** записи для:
- Товара (`products`) — обязательно (иначе отзыв пропускается в `beforeCreate`)
- Магазина (`shops`) — обязательно (иначе отзыв пропускается в `beforeCreate`)

## Пример исходящего события

```json
{
    "event_id": "uuid",
    "event_timestamp": "2024-01-20T12:00:00+03:00",
    "event_source": "storefront",
    "id": "15",
    "source": "storefront",
    "product_id": "ext-prod-123",
    "product_source": "1c",
    "shop_id": "ext-shop-1",
    "shop_source": "crm",
    "user_id": "42",
    "user_source": "storefront",
    "rating": 5,
    "description": "Отличный товар!",
    "author_name": "Иван П.",
    "author_phone": "+79991234567",
    "is_published": true,
    "created_at": "2024-01-20T12:00:00+03:00"
}
```
