# ProductConverter

**Файл:** `app/Services/Converters/ProductConverter.php`
**Наследует:** `BaseConverter`

Конвертер данных товаров между форматом системы и RetailCRM.

## toRetailCRM (Система → RetailCRM)

### Входные данные

| Поле | Тип | Описание |
|---|---|---|
| `title` / `name` | string | Название товара |
| `sku` / `article` | string | Артикул |
| `id` | string | Внешний ID |
| `price` | float | Цена |
| `weight` | int | Вес в граммах |
| `quantity` | float | Остаток |
| `active` | bool | Активность |
| `barcodes` | array | Штрихкоды |
| `description` | string | Описание |
| `image_url` | string | URL изображения |
| `manufacturer` | string | Производитель |

### Выходные данные (RetailCRM)

```php
[
    'name' => 'Название',
    'article' => 'SKU-123',
    'active' => true,
    'externalId' => '550e8400-...',
    'price' => 999.99,        // для совместимости
    'weight' => 500,
    'quantity' => 10.0,
    'description' => '...',
    'imageUrl' => 'https://...',
    'manufacturer' => 'Brand',
    'offers' => [
        [
            'article' => 'SKU-123',
            'externalId' => '550e8400-...',
            'prices' => [['code' => 'base', 'price' => 999.99]],
            'weight' => 500,
            'quantity' => 10.0,
            'barcodes' => ['4600000000001'],
        ]
    ],
]
```

**Важно:** Поле `groups` (категория) добавляется не в конвертере, а в `ProductConsumerHandler` после lookup в metadata.

## fromRetailCRM (RetailCRM → Система)

### Логика извлечения цены

Приоритет:
1. `offers[0].prices[]` → ищет `code === 'base'`
2. `offers[0].prices[0].price` — первая доступная цена
3. `offers[0].price` — старое поле (fallback)
4. `data['price']` — если нет offers

### Логика source/id

С metadata:
- `id = metadata.external_id`
- `source = metadata.source`

Без metadata:
- `id = data['externalId'] ?? data['id']`
- `source = retailcrmSource`

### Логика категории

Из `data['groups'][0].id`:
1. Ищет `EventSyncMetadata` по `entity_type='category'` и `entity_id=groupId`
2. Если найден → `category_id = metadata.external_id`, `category_source = metadata.source`
3. Fallback → `category_id = groupId`, `category_source = retailcrmSource`

### Выходные данные (Система)

```php
[
    'id' => 'uuid-or-external-id',
    'shop_code' => 'shop-1',
    'source' => 'bagisto',
    'sku' => 'SKU-123',
    'title' => 'Название',
    'price' => 999.99,
    'quantity' => 10.0,
    'weight' => 500,
    'active' => true,
    'unit_type' => 'piece',
    'updated_at' => '2024-01-01 12:00:00',
    'barcodes' => ['4600000000001'],
    'image_url' => 'https://...',
    'description' => '...',
    'manufacturer' => 'Brand',
    'category_id' => '123',
    'category_source' => 'bagisto',
]
```

## Связка с event_sync_metadata

### toRetailCRM — metadata НЕ используется

Конвертер формирует базовые поля товара и оффера. Поле `groups` (категория) добавляется **в handler** (`ProductConsumerHandler`) после lookup в `event_sync_metadata`.

### fromRetailCRM — metadata используется для двух целей

#### 1. Восстановление source/id самого товара

```
fromRetailCRM(data, metadata):

С metadata (передан как параметр):
  id = metadata.external_id       ← ID товара в исходной системе (1C, Bagisto)
  source = metadata.source         ← оригинальный источник

Без metadata:
  id = data['externalId'] ?? data['id']
  source = retailcrmSource         ← fallback
```

#### 2. Резолв категории товара

```
Если data['groups'][0] существует:
  categoryRetailId = groups[0]['id']

  SELECT event_sync_metadata
  WHERE entity_type = 'category'
    AND entity_id = categoryRetailId     ← ищем по ID в RetailCRM
  LIMIT 1

  Если найдена:
    category_id = metadata.external_id   ← оригинальный ID категории
    category_source = metadata.source    ← оригинальный источник

  Fallback:
    category_id = categoryRetailId       ← ID из RetailCRM as-is
    category_source = retailcrmSource
```

### Какие поля event_sync_metadata читаются

| Поле | Откуда | Зачем |
|---|---|---|
| `source` | параметр `$metadata` | Восстановить оригинальный источник товара |
| `external_id` | параметр `$metadata` | Восстановить оригинальный ID товара |
| `entity_id` | прямой SELECT (для категории) | Найти запись категории по её RetailCRM ID |
| `source` | прямой SELECT (для категории) | Оригинальный источник категории |
| `external_id` | прямой SELECT (для категории) | Оригинальный ID категории |
