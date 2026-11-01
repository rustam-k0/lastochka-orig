# OrderConverter

**Файл:** `app/Services/Converters/OrderConverter.php`
**Наследует:** `BaseConverter`

Конвертер данных заказов. Самый сложный конвертер — маппит статусы, методы доставки, клиентов, позиции, адреса, временные слоты.

## Свойства

| Свойство | Тип | Описание |
|---|---|---|
| `$statusMap` | array | Исходящий маппинг статусов (Система → CRM) |
| `$inboundStatusMap` | array | Входящий маппинг статусов (CRM → Система) |
| `$deliveryMethodMap` | array | Маппинг методов доставки |
| `$retailCrmService` | RetailCRMService\|null | Для резолва product externalId по offer ID |

## Конструктор

```php
__construct(array $config = null, ?RetailCRMService $retailCrmService = null)
```

- `$config['order_statuses']` → `$statusMap`
- `$config['order_statuses_inbound']` → `$inboundStatusMap` (или `array_flip($statusMap)` как fallback)
- `$config['delivery_methods']` → merge в `$deliveryMethodMap`

## Маппинг методов доставки

| Система | RetailCRM |
|---|---|
| `courier` | `courier` |
| `pickup` | `pickup` |
| `cdek` | `cdek` |
| `russian-post` | `russian_post` |
| `self-delivery` | `pickup` |

## toRetailCRM (Система → RetailCRM)

### Маппинг полей

| Система | RetailCRM |
|---|---|
| `id` / `externalId` | `externalId`, `number` |
| `shop_code` | `site` |
| `status` | `status` (через statusMap) |
| `client_info.name` | `firstName` |
| `client_info.surname` | `lastName` |
| `client_info.patronymic` | `patronymic` |
| `client_info.phone` | `phone` |
| `client_info.email` | `email` |
| `comment` | `customerComment` |
| `manager_comment` | `managerComment` |
| `items_subtotal` | `summ` |
| `grand_total` | `totalSumm` |
| `discount_total` | `discountManualAmount` |
| `is_paid` | `payments[0].status` (paid/not-paid) |

### Позиции заказа (items)

```php
// Вход
['id', 'quantity', 'unit_price', 'line_discount_total', 'product_name', 'created_at']

// Выход
['offer' => ['id'], 'quantity', 'initialPrice', 'discountTotal', 'externalId', 'displayName', 'createdAt']
```

### Доставка

```php
// Вход
['shipping_method', 'shipping_total', 'address' => [...], 'slot_start_date', 'slot_end_date', 'tracking_number']

// Выход
['delivery' => ['code', 'cost', 'address' => [...], 'date', 'time' => ['from', 'to'], 'trackNumber']]
```

### Адрес доставки

| Система | RetailCRM |
|---|---|
| `address.address` | `delivery.address.text` |
| `address.entrance` | `delivery.address.building` |
| `address.flat` | `delivery.address.flat` |
| `address.floor` | `delivery.address.floor` |
| `address.intercom` | `delivery.address.intercom` |
| `address.block` | `delivery.address.entrance` |
| `address.city` | `delivery.address.city` |
| `address.coordinates` | `delivery.address.coordinates` |

## fromRetailCRM (RetailCRM → Система)

### Определение типа оплаты

Перебирает `payments[]`:
- `bank-card`, `e-money`, `credit`, `invoice` → `payment_type = 'card'`
- Все остальные → `payment_type = 'cash'`

### Резолв source/id заказа

1. С metadata → `source = metadata.source`, `id = metadata.external_id`
2. Без metadata, есть externalId → `id = externalId`
3. Fallback → `id = data['id']`

### Резолв клиента

Из `data['customer']['id']`:
1. `resolveSourceAndExternalId('customer', id, shopCode)` → оригинальный source/external_id
2. Fallback на `customer.externalId`
3. Критический fallback: `'guest_' . id`

### Резолв товаров в позициях

Для каждого item трехэтапный поиск product_id:

1. **offer.externalId** → прямое использование + resolveSourceAndExternalId для source
2. **offer.id → getProductExternalIdByOfferId()** → API запрос для получения product.externalId
3. **Fallback** → `offer.id` или `offer.xmlId` или `'unknown_' . uniqid()`

### Метод `getProductExternalIdByOfferId`

Получает `product.externalId` по `offer.id` через API:
1. `getProducts({offerIds: [id]})` → берет `products[0].externalId`
2. Fallback: `getProducts({ids: [id]})` → проверяет offers, берет product.externalId
3. Кэширование через `static $cache` (в рамках одного запроса)

### Метод `findMetadataByExternalId`

Поиск metadata по `external_id` (не entity_id). Опционально фильтрует по `shop_code` с учетом NULL.

## Связка с event_sync_metadata

OrderConverter — **самый активный** пользователь metadata среди конвертеров. В `fromRetailCRM` он выполняет множество запросов к `event_sync_metadata` для резолва клиента, товаров и позиций.

### toRetailCRM — metadata НЕ используется

Конвертер формирует данные заказа напрямую из массива `$data`. Привязка клиента и резолв offer ID выполняются **в handler** (`OrderConsumerHandler.processCustomer`, `processOrderItems`).

### fromRetailCRM — множественные запросы к metadata

#### 1. Восстановление source/id самого заказа

```
С metadata:  id = metadata.external_id, source = metadata.source
Без metadata, есть externalId: id = externalId (оригинальный ID из источника)
Fallback:    id = data['id'] (RetailCRM internal ID)
```

#### 2. Резолв клиента (resolveSourceAndExternalId)

```
data['customer']['id'] = customerRetailId

resolveSourceAndExternalId('customer', customerRetailId, orderShopCode):
  └── findMetadataByEntityId('customer', customerRetailId, orderShopCode)
      └── SELECT event_sync_metadata WHERE entity_type='customer'
          AND entity_id=customerRetailId [AND shop_code=orderShopCode]

Результат:
  client_id = metadata.external_id     ← оригинальный ID клиента
  client_source = metadata.source      ← оригинальный источник
  Fallback: client_id = customerRetailId, client_source = retailcrmSource
```

#### 3. Резолв товаров в позициях (для каждого item)

Трёхэтапный поиск `product_id`:

```
1. offer.externalId есть:
   └── resolveSourceAndExternalId('product', externalId, orderShopCode)
       └── SELECT event_sync_metadata WHERE entity_type='product'
           AND entity_id=externalId

2. offer.externalId нет, offer.id есть:
   └── getProductExternalIdByOfferId(offerId) → API запрос
   └── findMetadataByExternalId('product', productExternalId, orderShopCode)
       └── SELECT event_sync_metadata WHERE entity_type='product'
           AND external_id=productExternalId
           AND (shop_code=orderShopCode OR shop_code IS NULL)

3. Fallback: offer.id / offer.xmlId / 'unknown_' + uniqid()
```

#### 4. Резолв позиций заказа (resolveSourceAndExternalId)

```
Для каждого item с item['id']:
  resolveSourceAndExternalId('order_item', itemRetailId, orderShopCode)
  └── SELECT event_sync_metadata WHERE entity_type='order_item'
      AND entity_id=itemRetailId

Результат:
  item.id = metadata.external_id       ← оригинальный ID позиции
  item.source = metadata.source        ← оригинальный источник
```

### Какие entity_type запрашиваются в fromRetailCRM

| entity_type | Метод запроса | Поиск по | Зачем |
|---|---|---|---|
| `customer` | `resolveSourceAndExternalId` | `entity_id` | Восстановить source/id клиента |
| `product` | `resolveSourceAndExternalId` | `entity_id` | Восстановить source/id товара (по externalId оффера) |
| `product` | `findMetadataByExternalId` | `external_id` | Найти source товара (по product.externalId из API) |
| `order_item` | `resolveSourceAndExternalId` | `entity_id` | Восстановить source/id позиции заказа |

### Метод findMetadataByExternalId (уникальный для OrderConverter)

В отличие от `findMetadataByEntityId` из BaseConverter (поиск по `entity_id`), этот метод ищет по **`external_id`**:

```
findMetadataByExternalId(entityType, externalId, shopCode?):
  SELECT event_sync_metadata
  WHERE entity_type = entityType
    AND external_id = externalId
    AND (shop_code = shopCode OR shop_code IS NULL)
  LIMIT 1
```

Нужен потому что при обратной конвертации товара из RetailCRM мы получаем `product.externalId` через API, а не `entity_id`.

### Выходные данные

```php
[
    'id' => 'uuid',
    'externalId' => '...',
    'shop_code' => 'shop-1',
    'source' => 'bagisto',
    'status' => 'created',
    'payment_type' => 'cash',
    'items_subtotal' => 1000,
    'discount_total' => 100,
    'items_total_after_discount' => 900,
    'shipping_total' => 200,
    'grand_total' => 1100,
    'is_paid' => true,
    'items' => [
        [
            'id' => 'item-uuid',
            'source' => 'bagisto',
            'product_id' => 'product-uuid',
            'product_source' => 'bagisto',
            'product_name' => 'Товар',
            'unit_price' => 500,
            'unit_price_effective' => 450,
            'line_subtotal' => 1000,
            'line_discount_total' => 100,
            'line_total' => 900,
            'quantity' => 2,
        ]
    ],
    'client_info' => ['name', 'surname', 'patronymic', 'phone', 'email'],
    'client_id' => 'customer-uuid',
    'client_source' => 'bagisto',
    'shipping_method' => 'courier',
    'address' => [...],
    'slot_start_date' => '2024-01-01 10:00:00',
    'slot_end_date' => '2024-01-01 12:00:00',
    'tracking_number' => 'TRACK123',
]
```
