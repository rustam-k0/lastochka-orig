# BannerEventTransformer

**Файл:** `app/RabbitMQ/Transformers/BannerEventTransformer.php`
**Модель:** `App\Models\Banner`
**Routing key (inbound):** `merchant_banners`
**Направление:** Только consume (inbound)

## Описание

Трансформер для обработки входящих событий баннеров из внешних систем. Баннеры привязаны к магазину и могут ссылаться на товар или категорию.

## Обязательные поля события

```php
['event_id', 'id', 'source', 'title', 'shop_id', 'shop_source']
```

## Маппинг полей (fromEvent)

| Поле события | Поле модели | Тип | Примечание |
|---|---|---|---|
| `shop_id` + `shop_source` | `store_id` | int | Резолвится через metadata → fallback по code |
| `source` | `source` | string | |
| `title` | `title` | string | |
| — | `image_path` | string | Пустая строка, заполняется в afterCreate |
| `link_type` | `link_type` | string | `none`, `product`, `category` |
| — | `link_product_id` | int\|null | Заполняется в afterCreate/afterUpdate |
| — | `link_category_id` | int\|null | Заполняется в afterCreate/afterUpdate |
| `is_active` | `is_active` | bool | По умолчанию `true` |

## Хуки жизненного цикла

### `beforeCreate`
- Проверяет что `store_id` найден
- Если магазин не найден — логирует warning и возвращает `null` (событие пропускается)

### `afterCreate`
1. **processImage** — скачивает изображение по `image_url` в папку `banners/`
2. **linkReferences** — связывает баннер с товаром или категорией

### `afterUpdate`
1. **processImage** — обновляет изображение (только если `image_url` присутствует)
2. **linkReferences** — обновляет связи

## Логика связывания (linkReferences)

| link_type | Поля события | Резолвится через | Записывается в |
|---|---|---|---|
| `product` | `product_id`, `product_source` | `resolveEntityId('products', ...)` | `link_product_id` |
| `category` | `category_id`, `category_source` | `resolveEntityId('categories', ...)` | `link_category_id` |

## Поиск существующей сущности (findExistingEntity)

Поиск по трём полям в таблице `banners`:
- `store_id` — из трансформированных данных
- `external_id` — из `eventData['id']`
- `source` — из `eventData['source']`

Включает soft-deleted записи (`withTrashed`).

## Связь с EventSyncMetadata

### Запись metadata для баннера

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `banners` |
| `entity_id` | ID созданного/обновлённого Banner |
| `external_id` | `eventData['id']` (например, `banner-123`) |
| `source` | `eventData['source']` |
| `shop_code` | `eventData['shop_code']` |

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `resolveStoreId()` | `shops` | `resolveEntityId('shops', shopId, shopSource)` | Поиск локального store_id по external_id магазина |
| `linkReferences()` (product) | `products` | `resolveEntityId('products', productId, productSource)` | Поиск локального product_id для связи баннера с товаром |
| `linkReferences()` (category) | `categories` | `resolveEntityId('categories', categoryId, categorySource)` | Поиск локального category_id для связи баннера с категорией |

### Порядок зависимости

Для корректной обработки баннера в metadata **должны уже существовать** записи для:
- Магазина (`shops`) — обязательно (иначе баннер пропускается в `beforeCreate`)
- Товара (`products`) — опционально (если `link_type = product`)
- Категории (`categories`) — опционально (если `link_type = category`)

Если связанный товар/категория ещё не синхронизированы, соответствующее поле (`link_product_id`/`link_category_id`) останется `null`.

## Пример входящего события

```json
{
    "event_id": "550e8400-e29b-41d4-a716-446655440000",
    "id": "banner-123",
    "source": "crm",
    "title": "Скидка 20%",
    "shop_id": "shop-1",
    "shop_source": "crm",
    "image_url": "https://example.com/banner.jpg",
    "link_type": "product",
    "product_id": "prod-456",
    "product_source": "crm",
    "is_active": true
}
```
