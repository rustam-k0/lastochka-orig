# ProductEventTransformer

**Файл:** `app/RabbitMQ/Transformers/ProductEventTransformer.php`
**Модель:** `App\Models\Product`
**Routing key (inbound):** `products`
**Направление:** Только consume (inbound)

## Описание

Трансформер для обработки входящих событий товаров из внешних систем (1С, CRM). Один из самых нагруженных трансформеров — обрабатывает полные события товаров и облегчённые события изменения остатков (`stock_changed`). Реализует инверсию цен при маппинге.

## Обязательные поля события

```php
['event_id', 'id', 'shop_code', 'source']
```

## Валидация (кастомная)

Переопределяет `validate()` базового класса с учётом типа события:

| Тип события | Дополнительные обязательные поля |
|---|---|
| `stock_changed` | `quantity` |
| Все остальные | `title`, `price` |

## Инверсия цен (ВАЖНО)

Ключевая особенность трансформера — **инверсия смысла цен**:

| Поле в событии | Значение | Поле в модели | Значение |
|---|---|---|---|
| `price` | Базовая цена (до скидки) | `price_old` | Старая/перечёркнутая цена |
| `discount_price` | Цена со скидкой | `price` | Текущая цена (что платит покупатель) |

**Логика:**
```php
$basePrice = round((float) $data['price'], 2);
$discountPrice = isset($data['discount_price']) ? round((float) $data['discount_price'], 2) : null;

'price' => $discountPrice ?? $basePrice,      // Текущая цена
'price_old' => $discountPrice ? $basePrice : null,  // Перечёркнутая (только если есть скидка)
```

## Маппинг полей (fromEvent)

| Поле события | Поле модели | Тип | Примечание |
|---|---|---|---|
| `shop_code` | `store_id` | int | Создаёт магазин если не существует |
| `source` | `source` | string | |
| — | `category_id` | int\|null | Через pending reference |
| `sku` | `sku` | string\|null | |
| `barcodes` | `barcodes` | string\|null | |
| `title` | `title` | string | |
| `description` | `description` | string\|null | |
| `additional_info` | `additional_info` | string\|null | Произвольный текст (напр. гарантия производителя). Conditional set: ключ отсутствует — значение не трогаем; явный `null` — очищаем |
| `meta_title` | `meta_title` | string\|null | SEO |
| `meta_description` | `meta_description` | string\|null | SEO |
| `meta_keywords` | `meta_keywords` | string\|null | SEO |
| `price` / `discount_price` | `price` / `price_old` | decimal(10,2) | Инверсия цен (см. выше) |
| `quantity` | `quantity` | int | По умолчанию `0` |
| `unit_type` | `unit_type` | string | `kg` → `Kg`, иначе `Piece` |
| `manufacturer` | `manufacturer` | string\|null | |
| `brand` | `brand` | string\|null | |
| `country` | `country` | string\|null | |
| `weight` | `weight` | int\|null | |
| `nds` | `vat_rate` | int\|null | |
| `active` | `is_active` | bool | По умолчанию `true` |
| `is_culinary` | `is_culinary` | bool | По умолчанию `false` |
| `sugar_free` | `sugar_free` | bool | По умолчанию `false` |

## Хуки жизненного цикла

### `beforeCreate`
- Проверяет наличие `store_id`
- Если магазин не найден — логирует warning и пропускает событие

### `afterCreate`
1. **linkCategory** — связь с категорией (или pending reference)
2. **processImage** — скачивание основного изображения
3. **processAttributes** — обработка атрибутов и извлечение КБЖУ

### `afterUpdate`
1. **linkCategory** — обновление связи с категорией
2. **processImage** — обновление изображения (если `image_url` присутствует)
3. **processAttributes** — обновление атрибутов (если `attributes` присутствует)

## Кастомный обработчик: processStockChangedEvent

Облегчённое обновление — только изменение `quantity`:
```php
$product->update(['quantity' => (int) ($eventData['quantity'] ?? 0)]);
```

## Кастомный обработчик: processPriceUpdatedEvent

Облегчённое обновление цены (`product.price_updated`, шлёт 1c_parser при изменении
базовой или акционной цены — не полный `product.created/updated`). Та же инверсия
цен, что в `fromEvent()` (см. выше): `discount_price` присутствует → идёт в `price`,
базовая `price` уходит в `price_old`. Отсутствие `discount_price` в событии явно
снимает `price_old` — так 1c_parser сигналит о завершении акции (перестаёт слать
акционную цену для этого товара).
```php
$basePrice = round((float) $eventData['price'], 2);
$discountPrice = isset($eventData['discount_price']) ? round((float) $eventData['discount_price'], 2) : null;

$product->update([
    'price' => $discountPrice ?? $basePrice,
    'price_old' => $discountPrice ? $basePrice : null,
    // + quantity_step/measurement_unit, если переданы
]);
```

## Обработка изображений (processImage)

Атомарная замена изображения через транзакцию:
1. Скачивание в папку `products/`
2. В транзакции: удаление старого primary-изображения (`position = 0`) → создание нового `ProductImage`

## Обработка атрибутов (processAttributes)

1. Удаление всех старых атрибутов товара
2. Создание новых `ProductAttribute` с позициями
3. Извлечение КБЖУ в основную модель (extractNutrition)

### Маппинг КБЖУ

| Код атрибута | Поле модели |
|---|---|
| `calories` | `calories` |
| `proteins` | `proteins` |
| `fats` | `fats` |
| `carbs` | `carbohydrates` |

## Связь с категорией (linkCategory)

Аналогично `CategoryEventTransformer.linkParent`:
1. Поиск через `resolveEntityId('categories', ...)` с учётом global_scope
2. Если найдена — обновляет `category_id`
3. Если не найдена — создаёт pending reference

## Поиск существующей сущности (findExistingEntity)

Поиск по двум полям:
- `store_id` — через `resolveOrCreateStoreByCode`
- `sku` — совпадение по SKU

Включает soft-deleted записи (`withTrashed`). Если `sku` отсутствует — возвращает `null`.

## Связь с EventSyncMetadata

### Запись metadata для товара

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `products` |
| `entity_id` | ID созданного/обновлённого Product |
| `external_id` | `eventData['id']` (например, `prod-123`) |
| `source` | `eventData['source']` |
| `shop_code` | `eventData['shop_code']` |

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `linkCategory()` | `categories` | `resolveEntityId('categories', categoryId, categorySource, shopCode)` | Поиск локального category_id для привязки товара к категории |

Поскольку категории — `global_scope` сущности, поиск выполняется **без фильтра по `shop_code`**.

### Pending references

Если категория ещё не существует в metadata (не была синхронизирована), `linkCategory()` создаёт **pending reference** через трейт `ResolvesExternalReferences`. Когда категория будет обработана и запись в `event_sync_metadata` появится — pending reference автоматически разрешится и `category_id` будет установлен.

### Кто зависит от metadata товаров

Записи `entity_type='products'` используются:
- **OrderEventTransformer** — `buildItems()` bulk-загружает external_id товаров для формирования payload заказа
- **ProductReviewEventTransformer** — `fromEvent()` ищет товар для привязки отзыва; `toEvent()` получает external_id для отправки
- **ProductGroupEventTransformer** — `syncProducts()` ищет товары для формирования группы
- **PromotionEventTransformer** — `syncProducts()` ищет товары для привязки к акции
- **BannerEventTransformer** — `linkReferences()` ищет товар для ссылки баннера
- **StoryEventTransformer** — `linkReferences()` ищет товар для ссылки истории

## Пример входящего события

```json
{
    "event_id": "uuid",
    "id": "prod-123",
    "shop_code": "SHOP1",
    "source": "1c",
    "event_type": "created",
    "title": "Молоко Простоквашино 1л",
    "description": "Молоко пастеризованное 3.2%",
    "sku": "4600000000001",
    "barcodes": "4600000000001",
    "price": 109.90,
    "discount_price": 89.90,
    "quantity": 50,
    "unit_type": "piece",
    "manufacturer": "Простоквашино",
    "brand": "Простоквашино",
    "country": "Россия",
    "weight": 1000,
    "nds": 10,
    "active": true,
    "category_id": "cat-dairy",
    "category_source": "1c",
    "image_url": "https://example.com/milk.jpg",
    "attributes": [
        {"code": "calories", "name": "Калории", "value": 58},
        {"code": "proteins", "name": "Белки", "value": 2.9},
        {"code": "fats", "name": "Жиры", "value": 3.2},
        {"code": "carbs", "name": "Углеводы", "value": 4.7},
        {
            "code": "nominal_voltage",
            "name": "Номинальное напряжение",
            "value": "230 В",
            "description": "Контент INFO-модалки (может отсутствовать/null)",
            "group": "general"
        }
    ]
}
```

Опциональные ключи атрибута (ELH-117): `description` — описание для INFO-модалки
в карточке товара, `group` — семантическая группа для вкладок экрана
«Характеристики» (`general` | `packaging` | `composition`). Сохраняются в
`product_attributes.description` / `product_attributes.group_code`; при
отсутствии ключей пишутся NULL, API (`ProductAttributeResource`) отдаёт
`group: "general"` как fallback.

## Пример события stock_changed

```json
{
    "event_id": "uuid",
    "id": "prod-123",
    "shop_code": "SHOP1",
    "source": "1c",
    "event_type": "stock_changed",
    "quantity": 25
}
```
