# DeliveryAddressConsumerHandler

**Файл:** `app/Services/Consumers/DeliveryAddressConsumerHandler.php`
**Наследует:** `BaseConsumerHandler`
**Entity type:** `delivery_address`

Обработчик событий адресов доставки (`customer.address.*`). Обновляет адрес клиента в RetailCRM (отдельной сущности адреса в CRM нет).

## Зависимости

| Сервис | Назначение |
|---|---|
| `CustomerConverter` | Конвертация (используется для доступа к базовым методам) |
| `RetailCRMService` | API вызовы |

## Валидация

Обязательные поля (помимо базовых):
- `address`
- `client_id`

## Алгоритм syncToRetailCRM

```
1. Поиск клиента в RetailCRM:
   ├── findRetailCrmId('customer', client_source, client_id, shop_code)
   └── Fallback: getCustomers({externalId: client_id})

2. Формирование addressData:
   ├── text = data['address']
   ├── flat = data['flat']
   ├── floor = data['floor']
   ├── block = data['entrance'] (подъезд)
   └── notes = "Домофон: {intercom}, Название: {title}"

3. updateCustomer(customerRetailId, {address: addressData}, 'id')

4. return customerRetailId (entity_id = ID клиента)
```

## Маппинг полей адреса

| Поле из события | Поле в RetailCRM |
|---|---|
| `address` | `address.text` |
| `flat` | `address.flat` |
| `floor` | `address.floor` |
| `entrance` | `address.block` |
| `intercom` | `address.notes` (часть) |
| `title` | `address.notes` (часть) |

## Особенности

### entity_id = customerRetailId
RetailCRM не имеет отдельной сущности адреса с собственным ID. Адрес — поле внутри клиента. Поэтому `entity_id` в metadata хранит ID клиента в RetailCRM.

### Отсутствие конвертера
Не использует отдельный конвертер для адреса — формирует данные напрямую в `syncToRetailCRM()`.

## Связка с event_sync_metadata

**Entity type:** `delivery_address`

### Как используется metadata в syncToRetailCRM

```
syncToRetailCRM(data, metadata, message):

1. Поиск клиента (ЧТЕНИЕ event_sync_metadata через findRetailCrmId):
   ├── findRetailCrmId('customer', client_source, client_id, shop_code)
   │   └── SELECT event_sync_metadata WHERE entity_type='customer'
   │       AND source=client_source AND external_id=client_id AND shop_code=shop_code
   │   └── Возвращает entity_id клиента в RetailCRM
   └── Fallback: getCustomers({externalId: client_id}) → прямой поиск в API

2. Обновление адреса клиента:
   └── updateCustomer(customerRetailId, {address: addressData}, 'id')

3. return customerRetailId → BaseConsumerHandler записывает в metadata.entity_id
```

### Какие поля metadata читаются

| Поле | Где читается | Зачем |
|---|---|---|
| `entity_id` (delivery_address) | BaseConsumerHandler — CREATE vs UPDATE | Не используется для update/create логики (всегда updateCustomer) |
| `entity_id` (customer) | `findRetailCrmId` | Найти ID клиента в RetailCRM для привязки адреса |

### Какие поля metadata записываются

| Поле | Где записывается | Значение |
|---|---|---|
| `entity_id` | BaseConsumerHandler (шаг 6) | **ID клиента** (не адреса!) в RetailCRM |
| `last_event_id` | BaseConsumerHandler (шаг 7) | UUID обработанного события |
| `last_event_timestamp` | BaseConsumerHandler (шаг 7) | Время события |

### Особенность: entity_id = customerRetailId

RetailCRM не имеет отдельной сущности адреса с собственным ID. Адрес — поле внутри клиента. Поэтому в `entity_id` записи `delivery_address` хранится **ID клиента** в RetailCRM, к которому привязан адрес.

### Кросс-ссылка на customer metadata

Адрес связан с клиентом через поля `client_id` и `client_source` из события. Поиск клиента идёт через запись `event_sync_metadata` с `entity_type='customer'`:

```
[delivery_address metadata]              [customer metadata]
entity_type: delivery_address            entity_type: customer
external_id: addr-123                    external_id: usr-456
entity_id: 5678  ─── одинаковый ───►    entity_id: 5678
```

### Пример записи в event_sync_metadata

```
entity_type: delivery_address
source: bagisto
external_id: addr-123-abc                            (ID адреса в Bagisto)
shop_code: shop-almaty
entity_id: 5678                                      (ID клиента в RetailCRM, НЕ адреса)
last_event_id: c3d4e5f6-...
last_event_timestamp: 2024-06-15 15:00:00
```
