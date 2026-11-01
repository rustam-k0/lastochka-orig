# PromotionEventTransformer

**Файл:** `app/RabbitMQ/Transformers/PromotionEventTransformer.php`
**Модель:** `App\Models\Promotion`
**Routing key (inbound):** `merchant_sales`
**Направление:** Только consume (inbound)

## Описание

Трансформер для обработки входящих событий акций (промо-кампаний). Акции привязаны к магазину, содержат список товаров и период действия. Связь с товарами реализована через pivot-таблицу (many-to-many).

## Обязательные поля события

```php
['event_id', 'id', 'source', 'title', 'shop_id', 'shop_source']
```

## Маппинг полей (fromEvent)

| Поле события | Поле модели | Тип | Примечание |
|---|---|---|---|
| `shop_id` + `shop_source` | `store_id` | int\|null | Через `resolveStoreIdByExternalId` |
| `source` | `source` | string | |
| `title` | `title` | string | |
| `description` | `description` | string\|null | |
| — | `image_path` | string\|null | Заполняется в afterCreate |
| `discount_percent` | `discount_percent` | int | По умолчанию `0` |
| `starts_at` | `starts_at` | datetime | По умолчанию `now()` |
| `ends_at` | `ends_at` | datetime | По умолчанию `now()->addMonth()` |
| `is_active` | `is_active` | bool | По умолчанию `true` |

## Хуки жизненного цикла

### `beforeCreate`
- Проверяет наличие `store_id`
- Если магазин не найден — логирует warning и пропускает событие

### `afterCreate`
1. **processImage** — скачивание изображения в папку `promotions/`
2. **syncProducts** — синхронизация товаров акции

### `afterUpdate`
1. **processImage** — обновление изображения (если `image_url` присутствует)
2. **syncProducts** — обновление товаров (если `products` присутствует)

## Синхронизация товаров (syncProducts)

Использует Eloquent `sync()` для many-to-many связи:
1. Резолвит каждый товар через `resolveEntityId('products', ...)`
2. Собирает массив product_id
3. Выполняет `$promotion->products()->sync($productIds)`

> **Отличие от ProductGroupEventTransformer:** Promotion использует pivot-таблицу (`sync`), а ProductGroup — промежуточную модель `ProductGroupItem` с позициями.

## Поиск существующей сущности (findExistingEntity)

Поиск по трём полям:
- `store_id` — из трансформированных данных
- `external_id` — из `eventData['id']`
- `source` — из `eventData['source']`

Включает soft-deleted записи (`withTrashed`).

## Связь с EventSyncMetadata

### Запись metadata для акции

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `promotions` |
| `entity_id` | ID созданной/обновлённой Promotion |
| `external_id` | `eventData['id']` (например, `promo-sale-123`) |
| `source` | `eventData['source']` |
| `shop_code` | Определяется пакетом |

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `fromEvent()` | `shops` | `resolveStoreIdByExternalId(shopId, shopSource)` | Поиск локального store_id (metadata → fallback по code) |
| `syncProducts()` | `products` | `resolveEntityId('products', productId, productSource)` | Поиск локальных product_id для привязки к акции через pivot |

### Порядок зависимости

Для корректной обработки акции в metadata **должны уже существовать** записи для:
- Магазина (`shops`) — обязательно (иначе акция пропускается в `beforeCreate`)
- Товаров (`products`) — желательно (товары, не найденные через metadata, пропускаются при syncProducts)

Если какой-то товар из списка `products` ещё не синхронизирован — он будет пропущен, но акция будет создана с остальными товарами.

## Пример входящего события

```json
{
    "event_id": "uuid",
    "id": "promo-sale-123",
    "source": "crm",
    "title": "Летняя распродажа",
    "description": "Скидки до 30% на молочные продукты",
    "shop_id": "shop-1",
    "shop_source": "crm",
    "image_url": "https://example.com/summer-sale.jpg",
    "discount_percent": 30,
    "starts_at": "2024-06-01",
    "ends_at": "2024-08-31",
    "is_active": true,
    "products": [
        {"product_id": "prod-1", "product_source": "1c"},
        {"product_id": "prod-2", "product_source": "1c"}
    ]
}
```
