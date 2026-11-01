# CustomerConsumerHandler

**Файл:** `app/Services/Consumers/CustomerConsumerHandler.php`
**Наследует:** `BaseConsumerHandler`
**Entity type:** `customer`

Обработчик событий клиентов (`customer.*`).

## Зависимости

| Сервис | Назначение |
|---|---|
| `CustomerConverter` | Конвертация данных |
| `RetailCRMService` | API вызовы |

## Валидация

Обязательные поля (помимо базовых):
- `phone` **или** `email` (минимум одно)

## Алгоритм syncToRetailCRM

```
1. Конвертация: converter.toRetailCRM(data)
   └── Добавляем site = shop_code

2. ОБНОВЛЕНИЕ (если metadata.entity_id есть)
   └── updateCustomer(entity_id, data, 'id')

3. ДЕДУПЛИКАЦИЯ (если entity_id нет)
   └── findExistingCustomer(data)
       ├── По телефону: getCustomers({name: phone}) — fuzzy
       └── По email: getCustomers({email: email})

4. СОЗДАНИЕ (если не найден существующий)
   └── createCustomer(data)
```

## Метод `findExistingCustomer`

Ищет существующего клиента для предотвращения дублей:

### По телефону (приоритет)
- Фильтр `name` в RetailCRM ищет по ФИО **или** телефону
- Телефон передается as-is (без нормализации) — RetailCRM делает fuzzy match
- Не передает `limit` — API требует `limit >= 20`

### По email (если телефон не нашел)
- Фильтр `email` — прямой поиск
- Аналогично без `limit`

## Метод `normalizePhone`

```php
// "8(999) 123-45-67" → "9991234567"
// "+7 999 123 45 67" → "9991234567"
```

Удаляет все кроме цифр. Если 11 цифр и начинается с 7/8 → убирает первую.

> Примечание: метод определен, но в текущей реализации `findExistingCustomer` телефон передается без нормализации, т.к. RetailCRM делает fuzzy match самостоятельно.

## Связка с event_sync_metadata

**Entity type:** `customer`

### Как используется metadata в syncToRetailCRM

```
syncToRetailCRM(data, metadata, message):

1. CREATE vs UPDATE (ЧТЕНИЕ metadata.entity_id):
   ├── metadata.entity_id есть → UPDATE (updateCustomer по entity_id, 'id')
   └── metadata.entity_id нет:
       ├── findExistingCustomer(data) → дедупликация по phone/email
       │   └── Найден → return existingCustomerId (НЕ создаём дубль)
       └── Не найден → CREATE (createCustomer)

2. Возвращает RetailCRM ID → BaseConsumerHandler записывает в metadata.entity_id
```

### Какие поля metadata читаются

| Поле | Где читается | Зачем |
|---|---|---|
| `entity_id` | CREATE vs UPDATE решение | Определяет, существует ли клиент в RetailCRM |

### Какие поля metadata записываются

| Поле | Где записывается | Значение |
|---|---|---|
| `entity_id` | BaseConsumerHandler (шаг 6) | Числовой ID клиента в RetailCRM |
| `last_event_id` | BaseConsumerHandler (шаг 7) | UUID обработанного события |
| `last_event_timestamp` | BaseConsumerHandler (шаг 7) | Время события |

### Особенность: дедупликация без metadata

При отсутствии `entity_id` клиент ищется напрямую в RetailCRM API по телефону/email (метод `findExistingCustomer`), а не через `event_sync_metadata`. Это нужно потому, что клиент мог быть создан в CRM вручную без привязки к metadata.

### Пример записи в event_sync_metadata

```
entity_type: customer
source: bagisto
external_id: usr-123-abc                             (ID клиента в Bagisto)
shop_code: shop-almaty
entity_id: 5678                                      (числовой ID в RetailCRM)
last_event_id: b2c3d4e5-...
last_event_timestamp: 2024-06-15 14:30:00
```
