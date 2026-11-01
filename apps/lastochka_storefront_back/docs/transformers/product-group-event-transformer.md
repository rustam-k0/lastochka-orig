# ProductGroupEventTransformer

**Файл:** `app/RabbitMQ/Transformers/ProductGroupEventTransformer.php`
**Модель:** `App\Models\ProductGroup`
**Routing key (inbound):** `merchant_product_groups`
**Направление:** Только consume (inbound)

## Описание

Трансформер для обработки входящих событий групп товаров (коллекций). Группы товаров могут отображаться на главной странице, странице магазина, в каталоге, и содержат упорядоченный список товаров.

## Обязательные поля события

```php
['event_id', 'id', 'source', 'title', 'shop_id', 'shop_source']
```

## Маппинг полей (fromEvent)

| Поле события | Поле модели | Тип | Примечание |
|---|---|---|---|
| `shop_id` + `shop_source` | `store_id` | int\|null | Через metadata → fallback по code |
| `source` | `source` | string | |
| `title` | `title` | string | |
| — | `image_path` | string\|null | Заполняется в afterCreate |
| `show_on_main` | `show_on_home` | bool | По умолчанию `false` |
| `show_on_merchant_page` | `show_on_store_page` | bool | По умолчанию `false` |
| `show_in_merchant_catalog` | `show_in_catalog` | bool | По умолчанию `false` |
| `is_recommended` | `is_recommended` | bool | По умолчанию `false` |
| `is_cart_page` | `is_cart_page` | bool | По умолчанию `false` |
| `is_active` | `is_active` | bool | По умолчанию `true` |

## Хуки жизненного цикла

### `beforeCreate`
- Проверяет наличие `store_id`
- Если магазин не найден — логирует warning и пропускает событие

### `afterCreate`
1. **processImage** — скачивание изображения в папку `product_groups/`
2. **syncProducts** — синхронизация списка товаров

### `afterUpdate`
1. **processImage** — обновление изображения (если `image_url` присутствует)
2. **syncProducts** — обновление товаров (если `products` присутствует)

## Синхронизация товаров (syncProducts)

Полная пересинхронизация через delete/create:
1. Удаление всех существующих `ProductGroupItem` для данной группы
2. Для каждого товара в событии:
   - Резолвинг `product_id` через `resolveEntityId('products', ...)`
   - Создание `ProductGroupItem` с позицией (индекс в массиве)
3. Товары, которые не найдены через metadata — пропускаются

## Поиск существующей сущности (findExistingEntity)

Поиск по трём полям:
- `store_id` — из трансформированных данных
- `external_id` — из `eventData['id']`
- `source` — из `eventData['source']`

Включает soft-deleted записи (`withTrashed`).

## Связь с EventSyncMetadata

### Запись metadata для группы товаров

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `product_groups` |
| `entity_id` | ID созданной/обновлённой ProductGroup |
| `external_id` | `eventData['id']` (например, `group-123`) |
| `source` | `eventData['source']` |
| `shop_code` | Определяется пакетом |

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `resolveStoreId()` | `shops` | `resolveEntityId('shops', shopId, shopSource)` | Поиск локального store_id по external_id магазина |
| `syncProducts()` | `products` | `resolveEntityId('products', productId, productSource)` | Поиск локальных product_id для формирования ProductGroupItem |

### Порядок зависимости

Для корректной обработки группы товаров в metadata **должны уже существовать** записи для:
- Магазина (`shops`) — обязательно (иначе группа пропускается в `beforeCreate`)
- Товаров (`products`) — желательно (товары, не найденные через metadata, пропускаются при syncProducts)

Если какой-то товар из списка `products` ещё не синхронизирован (нет записи в metadata) — он будет молча пропущен, но остальные товары группы будут привязаны корректно.

## Пример входящего события

```json
{
    "event_id": "uuid",
    "id": "group-123",
    "source": "crm",
    "title": "Хиты продаж",
    "shop_id": "shop-1",
    "shop_source": "crm",
    "image_url": "https://example.com/hits.jpg",
    "show_on_main": true,
    "show_on_merchant_page": true,
    "show_in_merchant_catalog": false,
    "is_recommended": true,
    "is_cart_page": false,
    "is_active": true,
    "products": [
        {"product_id": "prod-1", "product_source": "1c"},
        {"product_id": "prod-2", "product_source": "1c"},
        {"product_id": "prod-3", "product_source": "1c"}
    ]
}
```
