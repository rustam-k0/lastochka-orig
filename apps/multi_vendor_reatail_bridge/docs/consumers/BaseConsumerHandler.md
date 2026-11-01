# BaseConsumerHandler

**Файл:** `app/Services/Consumers/BaseConsumerHandler.php`

Абстрактный базовый класс для всех обработчиков событий из RabbitMQ. Реализует SELECT-first паттерн для работы с метаданными.

## Свойства

| Свойство | Тип | Описание |
|---|---|---|
| `$entityType` | `string` | Тип сущности (product, category, order и т.д.) — задается в наследнике |
| `$retailCrmService` | `RetailCRMService` | Сервис для работы с API RetailCRM |

## Алгоритм обработки (метод `handle`)

```
1. validateStructure($data) — базовая валидация JSON
2. Loopback prevention — если event_source === currentSource → ACK (пропуск)
3. processWithMetadata() — основная логика:
   3.1. getOrCreateMetadata() — SELECT-first: поиск/создание метаданных
   3.2. isEventAlreadyProcessed() — идемпотентность
   3.3. syncToRetailCRM() — абстрактный метод (реализуется в наследнике)
   3.4. Обновление metadata (entity_id, last_event_id, last_event_timestamp)
```

## Сценарии обработки

| source | event_source | Действие |
|---|---|---|
| `retailbridge` | `retailbridge` | ПРОПУСТИТЬ (loopback) |
| `retailbridge` | `admin` | СИНХРОНИЗИРОВАТЬ (наша сущность изменена в админке) |
| `retailcrm` | `retailbridge` | ПРОПУСТИТЬ (loopback) |
| `bagisto` | `bagisto` | СИНХРОНИЗИРОВАТЬ (внешняя сущность) |

## Метод `getOrCreateMetadata`

SELECT-first паттерн с разной логикой для локальных и внешних сущностей:

### Локальная сущность (source === currentSource)
- Ищет по `entity_id` (внутренний ID в RetailCRM)
- При создании: `entity_id = external_id`
- Race condition: ловит `Duplicate entry`, повторяет SELECT с `lockForUpdate()`

### Внешняя сущность (source !== currentSource)
- Ищет по `(entity_type, source, external_id, shop_code)`
- При создании: `entity_id = null` (заполнится после syncToRetailCRM)
- Race condition: аналогичная обработка дубликатов

## Метод `isEventAlreadyProcessed`

Два уровня проверки идемпотентности:

1. **По event_id (UUID):** если `metadata.last_event_id === eventId` → дубликат
2. **По timestamp:** если `eventTimestamp <= metadata.last_event_timestamp` → устаревшее событие

## Метод `findRetailCrmId`

Ищет ID сущности в RetailCRM для связанных сущностей (например, parent_id категории).

Цепочка поиска:
1. Поиск в `EventSyncMetadata` по `(entity_type, source, external_id, shop_code)`
2. Если source === retailcrm и не найдено → `fetchRetailCrmEntity()` — прямой GET из CRM
3. Если все равно не найдено → `createRetailCrmEntityStub()` — создание заглушки
4. Создание записи в metadata с реальным ID

### fetchRetailCrmEntity

Поддерживает типы: `category` (getProductGroups), `product` (getProducts, возвращает offer ID), `customer` (getCustomers).

### createRetailCrmEntityStub

Создает минимальную заглушку для восстановления целостности:
- `category`: `{name: "Category {id} (Recovered)", externalId, site, active: true}`
- `customer`: `{firstName: "Customer {id}", lastName: "(Recovered)", externalId, site}`

## Метод `shouldSyncToRetailCRM`

| source | Результат | Причина |
|---|---|---|
| `retailcrm` | `false` | Сущность уже в CRM, не синхронизируем обратно |
| Любой другой | `true` | Внешняя или локальная сущность — синхронизируем |

## Абстрактные методы

```php
abstract protected function syncToRetailCRM(
    array $data,
    EventSyncMetadata $metadata,
    AMQPMessage $message
): ?string;  // Возвращает entity_id в RetailCRM или null
```

## Метод `validateStructure`

Базовая валидация — проверяет наличие `id` и `source`. Наследники переопределяют для дополнительных проверок.

## Связка с event_sync_metadata

`BaseConsumerHandler` — центральный класс, определяющий **весь жизненный цикл** работы с `event_sync_metadata`. Каждый наследник наследует эту логику.

### Используемые поля таблицы

| Поле | Чтение | Запись | Назначение в BaseConsumerHandler |
|---|---|---|---|
| `entity_type` | `getOrCreateMetadata`, `findRetailCrmId` | `getOrCreateMetadata` | Определяет тип сущности (`$this->entityType`) |
| `entity_id` | `getOrCreateMetadata` (поиск локальных), `findRetailCrmId` | `processWithMetadata` (шаг 6), `getOrCreateMetadata` | ID сущности в RetailCRM. Заполняется после `syncToRetailCRM()` |
| `source` | `getOrCreateMetadata` (поиск внешних), `findRetailCrmId` | `getOrCreateMetadata` | Источник-владелец сущности |
| `external_id` | `getOrCreateMetadata` (поиск внешних), `findRetailCrmId` | `getOrCreateMetadata` | ID сущности во внешней системе |
| `shop_code` | `getOrCreateMetadata`, `findRetailCrmId` | `getOrCreateMetadata` | Код магазина/site |
| `last_event_id` | `isEventAlreadyProcessed` | `processWithMetadata` (шаг 7) | UUID последнего обработанного события |
| `last_event_timestamp` | `isEventAlreadyProcessed` | `processWithMetadata` (шаг 7) | Timestamp последнего события |

### Жизненный цикл записи metadata

```
handle() → processWithMetadata() [DB-транзакция]:

1. getOrCreateMetadata(source, externalId, shopCode)
   ├── Локальная сущность (source === currentSource):
   │   ├── SELECT WHERE entity_type + entity_id = externalId → lockForUpdate()
   │   └── INSERT: entity_id = externalId, external_id = externalId
   └── Внешняя сущность (source !== currentSource):
       ├── SELECT WHERE entity_type + source + external_id + shop_code
       └── INSERT: entity_id = NULL (заполнится на шаге 6)

2. isEventAlreadyProcessed(metadata, eventId, eventTimestamp)
   ├── Читает: last_event_id, last_event_timestamp
   └── Дубликат → return true (ACK без обработки)

3. syncToRetailCRM(data, metadata, message) → возвращает retailCrmId
   └── [реализуется в наследнике — может читать/писать metadata]

4. metadata.entity_id = retailCrmId          ← ЗАПИСЬ
5. metadata.last_event_id = eventId          ← ЗАПИСЬ
6. metadata.last_event_timestamp = now()     ← ЗАПИСЬ
7. metadata.save()
```

### findRetailCrmId — каскадное восстановление metadata

Когда наследнику нужно найти связанную сущность (например, parent category), `findRetailCrmId` выполняет каскадный поиск с возможным **созданием новых записей** в `event_sync_metadata`:

```
findRetailCrmId(entityType, source, externalId, shopCode):

1. SELECT из event_sync_metadata WHERE (entity_type, source, external_id, shop_code)
   └── Найдено → return entity_id

2. Если source === retailcrm и не найдено:
   ├── fetchRetailCrmEntity() → GET из API RetailCRM
   │   └── Поддержка: category (getProductGroups), product (getProducts → offer.id), customer (getCustomers)
   ├── Если не найдено → createRetailCrmEntityStub() → создание заглушки в CRM
   │   └── Поддержка: category (stub), customer (stub)
   └── INSERT в event_sync_metadata: entity_id = реальный ID из CRM ← ЗАПИСЬ

3. Не найдено → return null
```

### Race condition handling

При конкурентном создании записей в `getOrCreateMetadata`:
- INSERT ловит `QueryException` с кодом `23000` (Duplicate entry)
- Повторный SELECT с `lockForUpdate()` возвращает запись, созданную другим процессом
