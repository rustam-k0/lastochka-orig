# ShopConsumerHandler

**Файл:** `app/Services/Consumers/ShopConsumerHandler.php`
**Наследует:** `BaseConsumerHandler`
**Entity type:** `shop`

Обработчик событий магазинов (`shop.*`).

## Зависимости

| Сервис | Назначение |
|---|---|
| `ShopConverter` | Конвертация данных |
| `RetailCRMService` | API вызовы |
| `EntityHashService` | Change detection |

## Обрабатываемые события

| Routing key | Действие |
|---|---|
| `shop.created` | Создание site в RetailCRM |
| `shop.updated` | Обновление site в RetailCRM |
| `shop.deleted` | Только логирование (RetailCRM не поддерживает удаление sites) |

## Переопределение `handle`

Перехватывает `shop.deleted` до вызова `parent::handle()`:
- Определяет тип из `$message->getRoutingKey()`
- Для `shop.deleted` → лог + `return true` (ACK без обработки)

## Валидация

Обязательные поля:
- `id`, `source` (базовые)
- `shop_code` (обязателен для магазинов)

Предупреждение (не отклонение) при невалидном shop_code: `[^a-zA-Z0-9_-]` — конвертер очистит.

## Алгоритм syncToRetailCRM

```
1. converter.toRetailCRM(data)
   └── shopCode = siteData['code']

2. retailCrmService.editSite(siteData) — upsert
   └── Неуспешно → return null

3. Сохранение расширенных данных
   └── converter.extractExtendedData(data) → metadata.data

4. hashService.saveHashAfterSync('shop', shopCode)

5. return shopCode (используется как entity_id)
```

## Особенности

### entity_id = shop_code
Sites в RetailCRM не имеют числового ID. Вместо этого `entity_id` хранит `code` сайта.

### editSite как upsert
Метод `reference/sites/edit` в RetailCRM работает как upsert — создает или обновляет site по `code`.

### Расширенные данные в metadata.data
Поля, не поддерживаемые API sites напрямую, сохраняются в `metadata.data`:
- `schedule` — расписание работы
- `latitude`, `longitude` — координаты
- `slot_time` — время слота
- `open_24_7` — круглосуточный режим
- `active` — статус активности

## Связка с event_sync_metadata

**Entity type:** `shop`

### Как используется metadata в syncToRetailCRM

```
syncToRetailCRM(data, metadata, message):

1. Конвертация → siteData = converter.toRetailCRM(data)

2. retailCrmService.editSite(siteData) — upsert в RetailCRM

3. ЗАПИСЬ в metadata.data (расширенные данные):
   └── metadata.data = converter.extractExtendedData(data)
       Сохраняет: schedule, latitude, longitude, slot_time, open_24_7, active

4. return shopCode → BaseConsumerHandler записывает в metadata.entity_id
```

### Какие поля metadata читаются

| Поле | Где читается | Зачем |
|---|---|---|
| `entity_id` | Не используется в syncToRetailCRM | editSite — upsert, всегда вызывается |

### Какие поля metadata записываются

| Поле | Где записывается | Значение |
|---|---|---|
| `entity_id` | BaseConsumerHandler (шаг 6) | **shop_code** (строка, не число!) |
| `data` | `syncToRetailCRM` (шаг 3) | JSON с расширенными данными магазина |
| `last_event_id` | BaseConsumerHandler (шаг 7) | UUID обработанного события |
| `last_event_timestamp` | BaseConsumerHandler (шаг 7) | Время события |

### Особенность: entity_id = shop_code (строка)

В отличие от всех остальных сущностей, где `entity_id` — числовой ID из RetailCRM, для магазинов `entity_id` хранит **код site** (строку). Это потому что API `reference/sites` в RetailCRM идентифицирует sites по `code`, а не по числовому ID.

### Особенность: metadata.data как хранилище

Поле `data` (JSON) используется как расширенное хранилище для полей, которые **не поддерживаются API sites** в RetailCRM, но нужны другим модулям системы (например, для расчёта слотов доставки).

### Пример записи в event_sync_metadata

```
entity_type: shop
source: bagisto
external_id: shop-uuid-123
shop_code: shop-almaty
entity_id: shop-almaty                               (код site, НЕ число)
data: {
    "schedule": {"mon": "09:00-21:00", ...},
    "latitude": 43.2387,
    "longitude": 76.9458,
    "slot_time": 60,
    "open_24_7": false,
    "active": true
}
last_event_id: d4e5f6g7-...
last_event_timestamp: 2024-06-15 16:00:00
```
