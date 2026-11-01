# StoryEventTransformer

**Файл:** `app/RabbitMQ/Transformers/StoryEventTransformer.php`
**Модель:** `App\Models\Story`
**Routing key (inbound):** `stories`
**Направление:** Только consume (inbound)

## Описание

Трансформер для обработки входящих событий историй (stories). Истории — это контентные карточки с thumbnail и набором изображений, которые могут ссылаться на товар, магазин или внешний URL. Поддерживает нормализацию `link_type` между системами.

## Обязательные поля события

```php
['event_id', 'id', 'source', 'title']
```

> **Примечание:** Минимальный набор обязательных полей среди всех трансформеров — не требуется привязка к магазину.

## Маппинг полей (fromEvent)

| Поле события | Поле модели | Тип | Примечание |
|---|---|---|---|
| `source` | `source` | string | |
| `title` | `title` | string | |
| `expires_at` | `expires_at` | datetime | По умолчанию `now()->addMonth()` |
| `link_type` | `link_type` | string | Нормализация: `shop` → `store` |
| — | `link_product_id` | int\|null | Заполняется в afterCreate |
| — | `link_store_id` | int\|null | Заполняется в afterCreate |
| `external_url` | `link_external_url` | string\|null | |
| `is_active` | `is_active` | bool | По умолчанию `true` |
| — | `thumbnail_path` | string | Пустая строка, заполняется в afterCreate |

## Нормализация link_type

Внешняя система может отправлять `"shop"`, но мобильное приложение ожидает `"store"`:
```php
if ($linkType === 'shop') {
    $linkType = 'store';
}
```

## Хуки жизненного цикла

### `afterCreate`
1. **processThumbnail** — скачивание thumbnail в `stories/thumbnails/`
2. **processImages** — скачивание основных изображений в `stories/`
3. **linkReferences** — связь с товаром или магазином

### `afterUpdate`
1. **processThumbnail** — обновление thumbnail (если `thumbnail_image_url` присутствует)
2. **processImages** — обновление изображений (если `images` присутствует)
3. **linkReferences** — обновление связей

## Обработка изображений

### processThumbnail
Скачивает одно изображение-превью по `thumbnail_image_url` → сохраняет путь в `thumbnail_path`.

### processImages
Полная пересинхронизация изображений:
1. Удаление всех старых `StoryImage` для данной истории
2. Для каждого изображения из массива `images`:
   - Скачивание по `url`
   - Создание `StoryImage` с `position` = индекс в массиве
3. Ошибка скачивания одного изображения не прерывает обработку остальных

## Связывание (linkReferences)

| link_type | Поля события | Резолвится через | Записывается в |
|---|---|---|---|
| `product` | `product_id`, `product_source` | `resolveEntityId('products', ...)` | `link_product_id` |
| `store` или `shop` | `shop_id`, `shop_source` | `resolveEntityId('shops', ...)` | `link_store_id` |

## Поиск существующей сущности (findExistingEntity)

Поиск по двум полям:
- `external_id` — из `eventData['id']`
- `source` — из `eventData['source']`

Включает soft-deleted записи (`withTrashed`).

> **Примечание:** В отличие от большинства трансформеров, поиск **не привязан к магазину** — истории глобальные.

## Связь с EventSyncMetadata

### Запись metadata для истории

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `stories` |
| `entity_id` | ID созданной/обновлённой Story |
| `external_id` | `eventData['id']` (например, `story-123`) |
| `source` | `eventData['source']` |
| `shop_code` | Определяется пакетом (может быть NULL, т.к. истории не привязаны к магазину) |

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `linkReferences()` (product) | `products` | `resolveEntityId('products', productId, productSource)` | Поиск локального product_id для связи истории с товаром |
| `linkReferences()` (store) | `shops` | `resolveEntityId('shops', shopId, shopSource)` | Поиск локального store_id для связи истории с магазином |

### Порядок зависимости

Связи с товаром и магазином — **опциональные** (зависят от `link_type`):
- Товар (`products`) — нужен если `link_type = product`
- Магазин (`shops`) — нужен если `link_type = store` или `link_type = shop`

Если связанная сущность ещё не синхронизирована (нет записи в metadata), соответствующее поле (`link_product_id`/`link_store_id`) останется `null`. История будет создана, но без ссылки.

> **Примечание:** В отличие от CategoryEventTransformer и ProductEventTransformer, StoryEventTransformer **не создаёт pending references** — ссылки просто пропускаются.

## Пример входящего события

```json
{
    "event_id": "uuid",
    "id": "story-123",
    "source": "crm",
    "title": "Новинки недели",
    "expires_at": "2024-02-20",
    "link_type": "product",
    "product_id": "prod-456",
    "product_source": "1c",
    "external_url": null,
    "is_active": true,
    "thumbnail_image_url": "https://example.com/thumb.jpg",
    "images": [
        {"url": "https://example.com/slide1.jpg"},
        {"url": "https://example.com/slide2.jpg"},
        {"url": "https://example.com/slide3.jpg"}
    ]
}
```

## Пример с link_type = store

```json
{
    "event_id": "uuid",
    "id": "story-456",
    "source": "crm",
    "title": "Новый магазин открылся!",
    "link_type": "shop",
    "shop_id": "shop-1",
    "shop_source": "crm",
    "thumbnail_image_url": "https://example.com/new-store.jpg",
    "images": [
        {"url": "https://example.com/store-interior.jpg"}
    ]
}
```
