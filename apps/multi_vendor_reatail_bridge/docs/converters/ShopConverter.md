# ShopConverter

**Файл:** `app/Services/Converters/ShopConverter.php`
**Наследует:** `BaseConverter`

Конвертер данных магазинов (sites в RetailCRM).

## toRetailCRM (Система → RetailCRM)

### Маппинг полей

| Система | RetailCRM |
|---|---|
| `shop_code` / `id` | `code` (через sanitizeShopCode) |
| `title` | `name` (fallback на code) |
| `url` | `url` |
| `country_iso` | `countryIso` (default: config `default_country_iso` = `'KZ'`) |
| `catalog_id` | `catalogId` (опционально) |
| `ordering` | `ordering` (опционально) |

### Выходные данные

```php
[
    'code' => 'shop-almaty',
    'name' => 'Магазин Алматы',
    'url' => 'https://shop.example.com',
    'countryIso' => 'KZ',
    'catalogId' => 1,      // опционально
    'ordering' => 10,       // опционально
]
```

## fromRetailCRM (RetailCRM → Система)

### Логика source/id

С metadata:
- `id = metadata.external_id`, `source = metadata.source`

Без metadata:
- `id = data['code'] ?? data['id']`, `source = retailcrmSource`

`shop_code` берется из `data['code']` (код сайта в CRM).

### Выходные данные

```php
[
    'id' => 'uuid-or-code',
    'shop_code' => 'shop-almaty',
    'source' => 'bagisto',
    'title' => 'Магазин Алматы',
    'active' => true,
    'updated_at' => '2024-06-01 12:00:00',  // always now()
]
```

## Метод `extractExtendedData`

Извлекает поля, не поддерживаемые API sites, для хранения в `metadata.data`:

```php
[
    'schedule' => [...],     // расписание работы
    'latitude' => 43.2387,   // координаты
    'longitude' => 76.9458,
    'slot_time' => 60,       // время слота в минутах
    'open_24_7' => false,    // круглосуточный режим
    'active' => true,        // статус активности
]
```

## Метод `sanitizeShopCode`

Очистка shop_code для соответствия требованиям RetailCRM:

```
Допустимые символы: [a-zA-Z0-9_-]
Максимальная длина: 50

Примеры:
"Shop Алматы!"   → "Shop--"    → "Shop"
"a--b--c"        → "a-b-c"
"-shop-"         → "shop"
"very_long_code..." (>50) → обрезается до 50 символов
```

Алгоритм:
1. Замена недопустимых символов на `-`
2. Замена множественных `-` на один
3. Trim `-` по краям
4. `substr(0, 50)`

## Связка с event_sync_metadata

### toRetailCRM — metadata НЕ используется

Конвертер формирует данные site напрямую из массива `$data`.

### fromRetailCRM — metadata используется для восстановления source/id

```
fromRetailCRM(data, metadata):

С metadata (передан как параметр):
  id = metadata.external_id       ← ID магазина в исходной системе
  source = metadata.source         ← оригинальный источник

Без metadata:
  id = data['code'] ?? data['id'] ← код site из RetailCRM
  source = retailcrmSource         ← fallback
```

`shop_code` всегда берётся из `data['code']` (код site в RetailCRM), независимо от наличия metadata.

### extractExtendedData — косвенная связь с metadata

Метод `extractExtendedData()` не обращается к `event_sync_metadata` напрямую, но его результат **сохраняется** в `metadata.data` обработчиком `ShopConsumerHandler`:

```
ShopConsumerHandler.syncToRetailCRM():
  extendedData = converter.extractExtendedData(data)
  metadata.data = extendedData    ← ЗАПИСЬ в event_sync_metadata.data
```

### Какие поля event_sync_metadata читаются

| Поле | Откуда | Зачем |
|---|---|---|
| `source` | параметр `$metadata` | Восстановить оригинальный источник магазина |
| `external_id` | параметр `$metadata` | Восстановить оригинальный ID магазина |

### Особенность

ShopConverter **не делает** прямых запросов к таблице `event_sync_metadata`. Но его метод `extractExtendedData()` формирует данные, которые handler записывает в поле `data` (JSON) записи metadata.
