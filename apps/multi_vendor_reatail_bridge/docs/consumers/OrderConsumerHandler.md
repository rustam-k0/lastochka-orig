# OrderConsumerHandler

**Файл:** `app/Services/Consumers/OrderConsumerHandler.php`
**Наследует:** `BaseConsumerHandler`
**Entity type:** `order`

Обработчик событий заказов (`order.*`).

## Зависимости

| Сервис | Назначение |
|---|---|
| `OrderConverter` | Конвертация данных |
| `RetailCRMService` | API вызовы |

## Валидация

Обязательные поля (помимо базовых):
- `items` — массив с минимум 1 элементом

## Алгоритм syncToRetailCRM

```
1. Конвертация: converter.toRetailCRM(data)
   └── Добавляем site = shop_code

2. processCustomer(data, orderData)
   └── Поиск/создание клиента

3. processOrderItems(data, orderData)
   └── Резолв товаров и offer ID

4. ОБНОВЛЕНИЕ (если metadata.entity_id есть)
   ├── updateOrder(orderData, 'id', entity_id)
   ├── При 404 (RetailCrmNotFoundException):
   │   └── createOrder() — fallback создание
   └── При другой ошибке:
       └── НЕ создаем новый (предотвращение дублей!)

5. СОЗДАНИЕ (если entity_id нет)
   └── createOrder(orderData)
```

## Метод `processCustomer`

Обработка клиента заказа. Четырехэтапный поиск с предотвращением дублирования:

```
1. findRetailCrmId('customer', client_source, client_id, shop_code)
   └── Найден → customer = {id: retailCrmId}

2. Если не найден → поиск в RetailCRM API:
   2.1. По externalId: getCustomers({externalId: client_id})
   2.2. По телефону: getCustomers({name: phone}) — fuzzy match
   2.3. По email: getCustomers({email: email})

3. Если нигде не найден → createCustomer()

4. Сохранение metadata для клиента
   └── getOrCreateMetadata() + обновление entity_id
```

Данные клиента берутся из `data['client_info']`: name, surname, email, phone.

## Метод `processOrderItems`

Обработка позиций заказа. Двухэтапный процесс:

### Этап 1: сбор данных
Для каждого item из `data['items']`:
1. Формирование `orderItem` (productName, quantity, initialPrice, discountManualAmount)
2. Если есть `item['id']` → `getOrCreateMetadata('order_item', id)` → если есть entity_id → `orderItem['id']`
3. Если есть `product_id` + `product_source` → `findRetailCrmId('product', ...)` → сбор в `productIdsToFetch`

### Этап 2: batch-актуализация Offer ID
1. `getProducts({ids: [...]})` — один запрос для всех товаров
2. Для каждого товара: берет `offers[0].id` как `offerId` (fallback на product.id)
3. Присваивает `orderItem['offer'] = {id: offerId}`

## Метод `syncOrderItemsMetadata`

Синхронизация ID позиций после создания заказа:
1. `getOrder(retailOrderId)` — GET заказ из CRM
2. Индексирует позиции по `externalId`
3. Сопоставляет `sourceItems[].id` с `retailItems[].externalId`
4. Обновляет `entity_id` в metadata для каждой позиции

## Предотвращение дублей заказов

При ошибке обновления (не 404):
- **НЕ создает** новый заказ
- Логирует ошибку с пометкой "Дубль НЕ создаётся"
- Возвращает `null`

Только при `RetailCrmNotFoundException` (404) разрешен fallthrough к созданию.

## Метод `normalizePhone`

Нормализация телефона для поиска:
- Удаляет все кроме цифр
- Если 11 цифр и начинается с 7 или 8 → убирает первую цифру

## Связка с event_sync_metadata

**Entity type:** `order` (основной), `customer` и `order_item` (дополнительные)

OrderConsumerHandler — **самый сложный** потребитель metadata. Он работает с **тремя типами сущностей** и создаёт записи для связанных объектов.

### Как используется metadata в syncToRetailCRM

```
syncToRetailCRM(data, metadata, message):

1. processCustomer(data, orderData):
   ├── ЧТЕНИЕ: findRetailCrmId('customer', client_source, client_id, shop_code)
   │   └── SELECT event_sync_metadata WHERE entity_type='customer' ...
   │   └── Найден → orderData['customer'] = {id: entity_id}
   └── Если не найден → поиск/создание клиента:
       ├── Поиск в API: по externalId, телефону, email
       ├── Если нигде нет → createCustomer()
       └── ЗАПИСЬ: getOrCreateMetadata('customer', client_id, shop_code)
           + UPDATE entity_id клиента ← ЗАПИСЬ в event_sync_metadata

2. processOrderItems(data, orderData):
   ├── Для каждого item:
   │   ├── ЗАПИСЬ: getOrCreateMetadata('order_item', item_id, shop_code)
   │   │   └── INSERT event_sync_metadata с entity_type='order_item'
   │   ├── ЧТЕНИЕ: itemMetadata.entity_id → orderItem['id'] (ID позиции в CRM)
   │   └── ЧТЕНИЕ: findRetailCrmId('product', product_source, product_id, shop_code)
   │       └── SELECT event_sync_metadata WHERE entity_type='product' ...
   │       └── Возвращает entity_id товара → batch-запрос offer ID
   └── Batch getProducts({ids: [...]}) → актуализация offer ID

3. CREATE vs UPDATE (ЧТЕНИЕ metadata.entity_id):
   ├── metadata.entity_id есть → UPDATE (updateOrder)
   │   ├── При 404 → fallback CREATE
   │   └── При другой ошибке → НЕ создаём (предотвращение дублей)
   └── metadata.entity_id нет → CREATE (createOrder)

4. Возвращает RetailCRM ID → BaseConsumerHandler записывает в metadata.entity_id
```

### syncOrderItemsMetadata — постобработка

После создания заказа синхронизирует ID позиций:

```
syncOrderItemsMetadata(retailOrderId, sourceItems, shopCode):

1. GET заказ из RetailCRM → получаем присвоенные ID позиций
2. Индексируем по externalId
3. Для каждого sourceItem:
   └── ЗАПИСЬ: getOrCreateMetadata('order_item', externalId, shopCode)
       + UPDATE entity_id = retailItemId  ← ЗАПИСЬ в event_sync_metadata
```

### Какие записи metadata создаются/читаются

| entity_type | Операция | Где в коде | Назначение |
|---|---|---|---|
| `order` | READ/WRITE | BaseConsumerHandler | Основная запись заказа |
| `customer` | READ | `processCustomer` → `findRetailCrmId` | Поиск ID клиента в CRM |
| `customer` | WRITE | `processCustomer` → `getOrCreateMetadata` | Создание записи для нового клиента |
| `order_item` | READ/WRITE | `processOrderItems` → `getOrCreateMetadata` | Привязка позиций заказа |
| `order_item` | WRITE | `syncOrderItemsMetadata` | Обновление entity_id позиций после создания |
| `product` | READ | `processOrderItems` → `findRetailCrmId` | Поиск ID товара для offer резолва |

### Пример записей в event_sync_metadata для одного заказа

```
── Заказ ──
entity_type: order
source: bagisto
external_id: ord-789-abc
shop_code: shop-almaty
entity_id: 1234                   (ID заказа в RetailCRM)

── Клиент заказа ──
entity_type: customer
source: bagisto
external_id: usr-456-def
shop_code: shop-almaty
entity_id: 5678                   (ID клиента в RetailCRM)

── Позиция 1 ──
entity_type: order_item
source: bagisto
external_id: item-001
shop_code: shop-almaty
entity_id: 9999                   (ID позиции в RetailCRM)

── Позиция 2 ──
entity_type: order_item
source: bagisto
external_id: item-002
shop_code: shop-almaty
entity_id: 10000                  (ID позиции в RetailCRM)
```
