# UserConsumerHandler

**Файл:** `app/Services/Consumers/UserConsumerHandler.php`
**Наследует:** `BaseConsumerHandler`
**Entity type:** `user`

Обработчик событий сотрудников (`user.*`). Маршрутизирует по полю `role`.

## Зависимости

| Сервис | Назначение |
|---|---|
| `UserConverter` | Конвертация данных |
| `RetailCRMService` | API вызовы |

## Свойства

| Свойство | Описание |
|---|---|
| `$collectorsDictionaryCode` | Код справочника для сборщиков (из `config('retailcrm.dictionaries.collectors')`, по умолчанию `'collectors'`) |

## Валидация

Обязательные поля (помимо базовых):
- `role`

## Маршрутизация по роли

```
syncToRetailCRM()
├── role === 'courier'   → syncCourier()
├── role === 'collector' → syncCollector()
└── другая роль          → return null (пропуск с логом)
```

## Метод `syncCourier`

Работа с API справочника курьеров (`reference/couriers`):

```
1. converter.toRetailCRM(data) → формат курьера
2. ОБНОВЛЕНИЕ (если metadata.entity_id)
   └── updateCourier(entity_id, data)
3. СОЗДАНИЕ
   └── createCourier(data)
   └── return response.id
```

RetailCRM entity_id для курьеров = **числовой ID**.

## Метод `syncCollector`

Работа с кастомным справочником (`custom-fields/dictionaries`):

```
1. converter.toRetailCRM(data) → формат элемента справочника
   └── Получаем collectorCode из результата
2. ensureCollectorsDictionary() — проверка/создание справочника
3. ОБНОВЛЕНИЕ (если metadata.entity_id)
   └── updateDictionaryElement(dictCode, entity_id, data)
4. СОЗДАНИЕ
   └── createDictionaryElement(dictCode, data)
   └── return collectorCode (не числовой ID!)
```

RetailCRM entity_id для сборщиков = **code элемента справочника** (строка, не число).

## Метод `ensureCollectorsDictionary`

Проверяет существование справочника `collectors` с кэшированием через `static $checked`:

```
1. getCustomDictionary(collectorsDictionaryCode)
   └── Найден → $checked = true
2. Не найден → createCustomDictionary({code, name: 'Сборщики'})
   └── Создан → $checked = true
```

Проверка выполняется **один раз** за время жизни процесса (consumer работает в loop).

## Связка с event_sync_metadata

**Entity type:** `user`

### Как используется metadata в syncToRetailCRM

```
syncToRetailCRM(data, metadata, message):

├── role === 'courier' → syncCourier(data, metadata):
│   ├── metadata.entity_id есть → UPDATE (updateCourier(entity_id, data))
│   └── metadata.entity_id нет → CREATE (createCourier) → return response.id
│
├── role === 'collector' → syncCollector(data, metadata):
│   ├── metadata.entity_id есть → UPDATE (updateDictionaryElement(dictCode, entity_id, data))
│   └── metadata.entity_id нет → CREATE (createDictionaryElement) → return collectorCode
│
└── другая роль → return null (пропуск)

Возвращённый ID → BaseConsumerHandler записывает в metadata.entity_id
```

### Какие поля metadata читаются

| Поле | Где читается | Зачем |
|---|---|---|
| `entity_id` | `syncCourier`, `syncCollector` | Определяет CREATE vs UPDATE |

### Какие поля metadata записываются

| Поле | Где записывается | Значение |
|---|---|---|
| `entity_id` | BaseConsumerHandler (шаг 6) | Зависит от роли (см. ниже) |
| `last_event_id` | BaseConsumerHandler (шаг 7) | UUID обработанного события |
| `last_event_timestamp` | BaseConsumerHandler (шаг 7) | Время события |

### Особенность: разный формат entity_id в зависимости от роли

| Роль | entity_id | Тип | Пример |
|---|---|---|---|
| `courier` | Числовой ID курьера в RetailCRM | int → string | `"42"` |
| `collector` | `code` элемента кастомного справочника | string | `"550e8400-e29b-..."` |

### Пример записей в event_sync_metadata

```
── Курьер ──
entity_type: user
source: admin
external_id: usr-courier-001
shop_code: null                    (обычно не привязаны к магазину)
entity_id: 42                      (числовой ID в reference/couriers)

── Сборщик ──
entity_type: user
source: admin
external_id: usr-collector-001
shop_code: null
entity_id: 550e8400-e29b-...       (code элемента в кастомном справочнике)
```
