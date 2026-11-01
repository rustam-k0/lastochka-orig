# UserConverter

**Файл:** `app/Services/Converters/UserConverter.php`
**Наследует:** `BaseConverter`

Конвертер данных сотрудников. Роутинг по роли: `courier` → формат API курьеров, `collector` → формат элемента кастомного справочника.

## toRetailCRM (Система → RetailCRM)

Маршрутизация:
```
role === 'courier'   → toCourierFormat()
role === 'collector' → toCollectorFormat()  (default)
```

### toCourierFormat (для API `reference/couriers`)

| Система | RetailCRM |
|---|---|
| `name` | `firstName` |
| `surname` | `lastName` |
| `patronymic` | `patronymic` |
| `active` | `active` |
| `phone` | `phone.number` |
| `email` | `email` |

Выход:
```php
[
    'firstName' => 'Иван',
    'lastName' => 'Иванов',
    'patronymic' => 'Иванович',
    'active' => true,
    'phone' => ['number' => '+79991234567'],
    'email' => 'ivan@example.com',
]
```

### toCollectorFormat (для кастомного справочника)

| Система | RetailCRM |
|---|---|
| `surname` + `name` + `patronymic` | `name` (полное имя) |
| `id` | `code` |
| `phone` | `description` |

Выход:
```php
[
    'code' => '550e8400-...',
    'name' => 'Иванов Иван Иванович',
    'description' => '+79991234567',   // опционально
]
```

Если имя пустое → `'Без имени'`.

## fromRetailCRM (RetailCRM → Система)

### Определение формата входных данных

- Есть `firstName`/`lastName` → формат курьера → `name`, `surname`, `patronymic` напрямую
- Нет → формат элемента справочника → `name` разбивается по пробелам:
  - 3 части: `[фамилия, имя, отчество]`
  - 1 часть: `name = fullName`, `surname = ''`

### Логика роли

Из `data['__role']` (добавляется поллером перед конвертацией), по умолчанию `'collector'`.

### Логика id

Приоритет: `data['code'] ?? data['id']`
- Для курьеров: числовой `id`
- Для сборщиков: `code` элемента справочника

### Выходные данные

```php
[
    'id' => 'code-or-id',
    'shop_code' => null,       // обычно не привязаны к магазину
    'source' => 'retailcrm',
    'active' => true,
    'role' => 'courier',       // или 'collector'
    'name' => 'Иван',
    'surname' => 'Иванов',
    'patronymic' => 'Иванович',
    'phone' => '+79991234567', // из data['phone']['number'] для курьеров
    'email' => 'ivan@example.com',
    'birthday' => null,
    'gender' => null,
    'created_at' => null,
    'updated_at' => '2024-06-01 12:00:00',  // always now()
]
```

## Связка с event_sync_metadata

### toRetailCRM — metadata НЕ используется

Конвертер формирует данные пользователя (курьера или сборщика) напрямую из массива `$data`. Маршрутизация по `role` не зависит от metadata.

### fromRetailCRM — metadata НЕ используется напрямую

В отличие от других конвертеров, UserConverter **не принимает** параметр `$metadata` в `fromRetailCRM` и **не выполняет** запросов к `event_sync_metadata`.

Логика определения `id`:
```
id = data['code'] ?? data['id']
  Для курьеров: числовой ID из RetailCRM
  Для сборщиков: code элемента справочника
```

`source` всегда устанавливается в `retailcrmSource`, т.к. при обратной конвертации (поллинг из RetailCRM) вызывающий код не передаёт metadata.

### Почему metadata не нужна

Пользователи (курьеры/сборщики) — это справочные данные, которые:
- Создаются преимущественно в одной системе (admin)
- Не имеют сложных связей с другими сущностями
- Идентифицируются уникально по `code` (сборщики) или `id` (курьеры)

Восстановление `source`/`external_id` выполняется на уровне **handler** через `BaseConsumerHandler.getOrCreateMetadata()`, а не в конвертере.
