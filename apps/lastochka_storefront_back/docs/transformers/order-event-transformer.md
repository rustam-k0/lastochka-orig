# OrderEventTransformer

**Файл:** `app/RabbitMQ/Transformers/OrderEventTransformer.php`
**Модель:** `App\Models\Order`
**Routing key (outbound):** `orders`
**Routing key (inbound):** `orders`
**Направление:** Двусторонний (publish + consume)

## Описание

Самый сложный трансформер в системе. Управляет отправкой заказов в RabbitMQ (с полным payload) и приёмом обновлений статусов от внешних систем. Поддерживает 4 кастомных типа событий.

## Обязательные поля события

```php
['event_id', 'id', 'source']
```

## Маппинг статусов

| Internal (OrderStatus) | RabbitMQ | Направление |
|---|---|---|
| `Pending` | `created` | Оба |
| `Assembling` | `assembling` | Оба |
| `Assembled` | `assembled` | Оба |
| `InDelivery` | `in_delivery` | Оба |
| `Delivered` | `delivered` | Оба |
| `Cancelled` | `cancelled` | Оба |
| — | `returned` | Inbound → `Cancelled` |

> **Примечание:** Статус `returned` маппится в `Cancelled`, а неизвестные статусы — в `Pending` (default).

## Исходящие события (toEvent)

### Стандартный toEvent

Полный payload заказа, загружает связи: `user`, `store`, `items`, `address`, `promocode`.

| Поле | Источник | Примечание |
|---|---|---|
| `event_type` | `'created'` | |
| `id` | `order->id` | String |
| `shop_code` | `order->store->code` | |
| `client_id` | `order->user_id` | String |
| `client_info` | `buildClientInfo()` | name, surname, phone, email |
| `items` | `buildItems()` | Массив товаров с external_id |
| `status` | `mapStatusToRabbit()` | |
| `items_subtotal` | `order->subtotal` | float (рубли) |
| `discount_total` | `order->discount` | float |
| `items_total_after_discount` | `subtotal - discount` | float |
| `shipping_total` | `order->delivery_cost` | float |
| `grand_total` | `order->total` | float |
| `is_paid` | `order->is_paid` | bool |
| `payment_type` | `order->payment_type` | Enum value |
| `slot_start_date` | `order->delivery_slot_start` | ISO 8601 |
| `slot_end_date` | `order->delivery_slot_end` | ISO 8601 |
| `slot_time_slot` | `order->delivery_slot_display` | |
| `slot_date` | `delivery_slot_start` | Y-m-d |
| `shipping_method` | `order->shipping_method` | Enum value |
| `address` | `buildAddress()` | Полный адрес с координатами |
| `promocode` | `buildPromocode()` | Код + процент скидки |

### Кастомные события (toCustomEvent)

| event_type | Описание | Загружаемые связи | Payload |
|---|---|---|---|
| `order_created` | Создание заказа | user, store, items, address, promocode | Полный (как toEvent, без event_id/source) |
| `order_paid` | Оплата заказа | store | event_type, is_paid, payment_type, updated_at |
| `order_cancelled` | Отмена заказа | store | event_type, status, updated_at |
| `order_supplemented` | Дополнение заказа | store, items | event_type, items, суммы, updated_at |

## Входящие события (fromEvent)

Минимальный маппинг — только обновление статуса:

| Поле события | Поле модели |
|---|---|
| `status` | `status` (через `mapStatusFromRabbit`) |
| `tracking_number` | `tracking_number` |

### processStatusChangedEvent

Специальный обработчик для события смены статуса от CRM:
```php
$order->update([
    'status' => $newStatus,
    'tracking_number' => $eventData['tracking_number'] ?? $order->tracking_number,
]);
```

## Вспомогательные методы

### `buildItems(Order $order): array`

Сборка массива товаров заказа. **Оптимизация N+1:** выполняет bulk-загрузку `EventSyncMetadata` для всех product_id одним запросом.

Каждый item содержит:
- `product_id` — external_id из metadata (или локальный id как fallback)
- `product_source` — source из metadata
- `unit_price`, `line_subtotal`, `line_total` — цены в рублях (float)
- `quantity`

### `buildAddress(Order $order): ?array`

Адрес доставки:
- `address` — полный адрес
- `entrance`, `intercom`, `floor`, `flat`
- `latitude`, `longitude`

### `buildPromocode(Order $order): ?array`

Промокод заказа:
- `code` — код промокода
- `discount_percent` — процент скидки (только для типа `percent`)

### `toRubles(int $amount): float`

Конвертация сумм. В БД хранятся как integer в рублях, отправляются как float.

## Связь с EventSyncMetadata

### Запись metadata для заказа

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `orders` |
| `entity_id` | ID созданного/обновлённого Order |
| `external_id` | `eventData['id']` (или `order->id` при outbound) |
| `source` | `eventData['source']` (или из конфига при outbound) |
| `shop_code` | `order->store->code` |

### Прямое использование EventSyncMetadata (outbound)

Метод `buildItems()` **напрямую** обращается к `EventSyncMetadata` для формирования payload товаров в заказе:

```php
$productMetas = EventSyncMetadata::where('entity_type', 'products')
    ->whereIn('entity_id', $productIds)
    ->get()
    ->keyBy('entity_id');
```

**Оптимизация N+1:** вместо отдельного запроса для каждого товара, выполняется один bulk-запрос для всех product_id в заказе.

Для каждого товара в заказе:
- `product_id` → берётся `external_id` из metadata (или локальный id как fallback)
- `product_source` → берётся `source` из metadata (или из конфига как fallback)

Это позволяет CRM/1C получать заказ с товарами, идентифицированными по **их собственным** ID, а не по локальным ID storefront_back.

### Чтение metadata других сущностей

| Где используется | Тип сущности | Метод | Назначение |
|---|---|---|---|
| `buildItems()` | `products` | Прямой запрос к `EventSyncMetadata` | Bulk-получение external_id всех товаров в заказе |

### Fallback при отсутствии metadata

Если для товара нет записи в `event_sync_metadata` (товар создан локально, без синхронизации):
- `product_id` = `(string) $item->product_id` (локальный ID)
- `product_source` = значение из конфига `rabbitmq-events.source`

## Пример исходящего события (order_created)

```json
{
    "event_id": "uuid",
    "event_type": "created",
    "event_source": "storefront",
    "id": "123",
    "shop_code": "SHOP1",
    "source": "storefront",
    "client_id": "42",
    "client_source": "storefront",
    "client_info": {
        "name": "Иван",
        "surname": "Петров",
        "phone": "+79991234567",
        "email": "ivan@example.com"
    },
    "items": [
        {
            "id": "1",
            "source": "storefront",
            "product_id": "ext-prod-1",
            "product_source": "1c",
            "product_name": "Молоко 1л",
            "unit_price": 89.0,
            "quantity": 2,
            "line_total": 178.0
        }
    ],
    "status": "created",
    "grand_total": 278.0,
    "shipping_total": 100.0,
    "is_paid": false,
    "payment_type": "online",
    "address": {
        "address": "ул. Ленина, 10",
        "latitude": 55.7558,
        "longitude": 37.6173
    }
}
```
