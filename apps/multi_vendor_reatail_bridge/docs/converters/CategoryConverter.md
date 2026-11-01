# CategoryConverter

**Файл:** `app/Services/Converters/CategoryConverter.php`
**Наследует:** `BaseConverter`

Конвертер данных категорий между форматом системы и RetailCRM (product groups).

## toRetailCRM (Система → RetailCRM)

### Входные данные

| Поле | Тип | Описание |
|---|---|---|
| `title` | string | Название категории |
| `id` | string | Внешний ID |
| `active` | bool | Активность |

### Выходные данные

```php
[
    'name' => 'Электроника',
    'active' => true,
    'externalId' => '550e8400-...',
]
```

**Важно:** `parentId` и `site` добавляются не в конвертере, а в `CategoryConsumerHandler` после lookup в metadata.

## fromRetailCRM (RetailCRM → Система)

### Логика source/id

С metadata:
- `id = metadata.external_id`, `source = metadata.source`

Без metadata:
- `id = data['externalId'] ?? data['id']`, `source = retailcrmSource`

### Логика родительской категории

Из `data['parentId']`:
1. Ищет `EventSyncMetadata` по `entity_type='category'` и `entity_id=parentId`
2. Найден → `parent_id = metadata.external_id`, `parent_source = metadata.source`
3. Fallback → `parent_id = parentId`, `parent_source = retailcrmSource`

### Логика updated_at

Из `data['updatedAt']`:
- Если массив с полем `date` → извлекает строку
- Если null → `now()->format('Y-m-d H:i:s')` (момент получения)

### Выходные данные

```php
[
    'id' => 'uuid',
    'shop_code' => 'shop-1',
    'source' => 'bagisto',
    'title' => 'Электроника',
    'parent_id' => 'parent-uuid',
    'parent_source' => 'bagisto',
    'active' => true,
    'updated_at' => '2024-01-01 12:00:00',
]
```

## Связка с event_sync_metadata

### toRetailCRM — metadata НЕ используется

Конвертер формирует базовые поля (`name`, `active`, `externalId`). Поля `parentId` и `site` добавляются **в handler** (`CategoryConsumerHandler`) после lookup в `event_sync_metadata`.

### fromRetailCRM — metadata используется для двух целей

#### 1. Восстановление source/id самой категории

```
fromRetailCRM(data, metadata):

С metadata (передан как параметр):
  id = metadata.external_id       ← ID в исходной системе (Bagisto, 1C и т.д.)
  source = metadata.source         ← оригинальный источник

Без metadata:
  id = data['externalId'] ?? data['id']
  source = retailcrmSource         ← fallback: считаем что из RetailCRM
```

#### 2. Резолв родительской категории

```
Если data['parentId'] существует:
  parentRetailId = data['parentId']

  SELECT event_sync_metadata
  WHERE entity_type = 'category'
    AND entity_id = parentRetailId     ← ищем по ID в RetailCRM
  LIMIT 1

  Если найдена:
    parent_id = metadata.external_id   ← оригинальный ID родителя
    parent_source = metadata.source    ← оригинальный источник родителя

  Fallback:
    parent_id = parentRetailId         ← ID из RetailCRM as-is
    parent_source = retailcrmSource
```

### Какие поля event_sync_metadata читаются

| Поле | Откуда | Зачем |
|---|---|---|
| `source` | параметр `$metadata` | Восстановить оригинальный источник категории |
| `external_id` | параметр `$metadata` | Восстановить оригинальный ID категории |
| `entity_id` | прямой SELECT (для родителя) | Найти запись родительской категории по её RetailCRM ID |
| `source` | прямой SELECT (для родителя) | Оригинальный источник родителя |
| `external_id` | прямой SELECT (для родителя) | Оригинальный ID родителя |
