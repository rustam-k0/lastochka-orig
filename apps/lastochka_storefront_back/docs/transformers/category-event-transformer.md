# CategoryEventTransformer

**Файл:** `app/RabbitMQ/Transformers/CategoryEventTransformer.php`
**Модель:** `App\Models\Category`
**Routing key (inbound):** `categories`
**Направление:** Только consume (inbound)

## Описание

Трансформер для обработки входящих событий категорий. Категории являются сущностями с `global_scope` — они являются справочниками и ищутся без привязки к `shop_code`. Поддерживает иерархическую структуру через `parent_id` с механизмом pending references.

## Обязательные поля события

```php
['event_id', 'id', 'shop_code', 'source', 'title']
```

## Маппинг полей (fromEvent)

| Поле события | Поле модели | Тип | Примечание |
|---|---|---|---|
| `shop_code` | `store_id` | int | Создаёт магазин если не существует (`resolveOrCreateStoreByCode`) |
| `source` | `source` | string | |
| — | `parent_id` | int\|null | Устанавливается в afterCreate/afterUpdate через pending reference |
| `title` | `name` | string | |
| — | `image_path` | string\|null | Устанавливается в afterCreate через downloadImage |
| `active` | `is_active` | bool | По умолчанию `true` |
| `meta_title` | `meta_title` | string\|null | SEO |
| `meta_description` | `meta_description` | string\|null | SEO |
| `meta_keywords` | `meta_keywords` | string\|null | SEO |

## Хуки жизненного цикла

### `afterCreate`
1. **processImage** — скачивает изображение по `image_url` в папку `categories/`
2. **linkParent** — связывает с родительской категорией

### `afterUpdate`
1. **processImage** — обновляет изображение (только если `image_url` присутствует)
2. **linkParent** — обновляет связь с родителем

## Механизм связывания с родителем (linkParent)

1. Получает `parent_id` и `parent_source` из события
2. Ищет родителя через `resolveEntityId('categories', ...)` — **с учётом global_scope** (без фильтра по shop_code)
3. Если родитель найден — обновляет `parent_id`
4. Если родитель ещё не создан — создаёт **pending reference** через трейт `ResolvesExternalReferences`

**Pending reference** автоматически разрешается когда родительская категория будет создана позднее.

## Поиск существующей сущности (findExistingEntity)

Поиск по двум полям в таблице `categories`:
- `store_id` — через `resolveOrCreateStoreByCode`
- `name` — совпадение по `eventData['title']`

Включает soft-deleted записи (`withTrashed`).

> **Примечание:** Поиск идёт по `name`, а не по `external_id`, что позволяет связывать существующие категории с внешними системами.

## Связь с EventSyncMetadata

### Запись metadata для категории

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `categories` |
| `entity_id` | ID созданной/обновлённой Category |
| `external_id` | `eventData['id']` (например, `cat-123`) |
| `source` | `eventData['source']` |
| `shop_code` | `eventData['shop_code']` (может быть проигнорирован при поиске) |

### global_scope

Категории помечены как `global_scope: true` в `config/rabbitmq-events.php`. Это означает:
- При поиске через `resolveEntityId('categories', ...)` фильтр по `shop_code` **не применяется**
- Категории — справочники, общие для всех магазинов
- В `event_sync_metadata` для категорий `shop_code` может быть `NULL`
- Другие трансформеры (Product, Banner) находят категории по external_id + source, без привязки к магазину

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `linkParent()` | `categories` | `resolveEntityId('categories', parentId, parentSource, shopCode)` | Поиск родительской категории по external_id (global_scope — без shop_code) |

### Pending references

Если родительская категория ещё не существует в metadata (не была синхронизирована), создаётся **pending reference** через трейт `ResolvesExternalReferences`. Когда родительская категория будет обработана и запись в `event_sync_metadata` появится — pending reference автоматически разрешится и `parent_id` будет установлен.

### Кто зависит от metadata категорий

Записи `entity_type='categories'` используются:
- **ProductEventTransformer** — `linkCategory()` ищет категорию для привязки товара
- **BannerEventTransformer** — `linkReferences()` ищет категорию для ссылки баннера

## Пример входящего события

```json
{
    "event_id": "550e8400-e29b-41d4-a716-446655440000",
    "id": "cat-123",
    "shop_code": "SHOP1",
    "source": "1c",
    "title": "Молочные продукты",
    "image_url": "https://example.com/dairy.jpg",
    "active": true,
    "parent_id": "cat-100",
    "parent_source": "1c",
    "meta_title": "Молочные продукты — купить с доставкой",
    "meta_description": "Широкий ассортимент молочных продуктов",
    "meta_keywords": "молоко, кефир, сметана"
}
```
