# CustomerConverter

**Файл:** `app/Services/Converters/CustomerConverter.php`
**Наследует:** `BaseConverter`

Конвертер данных клиентов.

## toRetailCRM (Система → RetailCRM)

### Маппинг полей

| Система | RetailCRM |
|---|---|
| `id` | `externalId` |
| `name` | `firstName` |
| `surname` | `lastName` |
| `patronymic` | `patronymic` |
| `phone` | `phones[0].number` |
| `email` | `email` |
| `birthday` | `birthday` |
| `gender` (`male`/`female`) | `sex` (`male`/`female`) |
| `shop_code` | `site` (через resolveShopCode) |

### Выходные данные

```php
[
    'externalId' => '550e8400-...',
    'firstName' => 'Иван',
    'lastName' => 'Иванов',
    'patronymic' => 'Иванович',
    'phones' => [['number' => '+79991234567']],
    'email' => 'ivan@example.com',
    'birthday' => '1990-01-01',
    'sex' => 'male',
    'site' => 'shop-1',
]
```

## fromRetailCRM (RetailCRM → Система)

### Логика телефона

Берет первый телефон из массива `phones[]`:
```php
$firstPhone = reset($data['phones']);
$phone = $firstPhone['number'] ?? '';
```

### Логика пола

Приоритет:
1. `data['sex']` — явно указанный
2. `data['presumableSex']` — предполагаемый (определяется RetailCRM)

### Логика source/id

С metadata:
- `id = metadata.external_id`, `source = metadata.source`

Без metadata:
- `id = data['id']`, `source = retailcrmSource`

### Выходные данные

```php
[
    'id' => 'uuid',
    'externalId' => '12345',
    'shop_code' => 'shop-1',
    'source' => 'bagisto',
    'name' => 'Иван',
    'surname' => 'Иванов',
    'patronymic' => 'Иванович',
    'phone' => '+79991234567',
    'email' => 'ivan@example.com',
    'birthday' => '1990-01-01',
    'gender' => 'male',
    'created_at' => '2024-01-01T12:00:00',
    'updated_at' => '2024-06-01T12:00:00',
]
```

## Связка с event_sync_metadata

### toRetailCRM — metadata НЕ используется

Конвертер формирует данные клиента напрямую из массива `$data`. Metadata не нужна — все поля берутся из события.

### fromRetailCRM — metadata используется только для восстановления source/id

```
fromRetailCRM(data, metadata):

С metadata (передан как параметр):
  id = metadata.external_id       ← ID клиента в исходной системе
  source = metadata.source         ← оригинальный источник

Без metadata:
  id = data['id']                  ← ID клиента в RetailCRM
  source = retailcrmSource         ← fallback
```

### Какие поля event_sync_metadata читаются

| Поле | Откуда | Зачем |
|---|---|---|
| `source` | параметр `$metadata` | Восстановить оригинальный источник клиента |
| `external_id` | параметр `$metadata` | Восстановить оригинальный ID клиента |

### Особенность

CustomerConverter **не делает** прямых запросов к таблице `event_sync_metadata`. Вся работа с metadata ограничена параметром `$metadata`, который передаётся вызывающим кодом (PollEntityCommand, WebhookController).
