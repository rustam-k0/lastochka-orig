# lastochka_purchase_back

Микросервис управления заказами через админ-панель и выдачи слотов доставки.

## Стек

- **PHP 8.1 / Laravel 9.x**
- **GraphQL** — Lighthouse 5.31, эндпоинт `/graphql-purchase`
- **RabbitMQ** — пакет `comrades/laravel-rabbitmq-events`, exchange `app_events` (topic), Transactional Outbox Pattern
- **Slots API** — пакет `cron/slots-api` для получения слотов доставки
- **Auth** — Laravel Sanctum, guard `api` (для админки)
- **Roles** — `spatie/laravel-permission`
- **Docker** — nginx:alpine + PHP 8.1-fpm, порт 8032, сеть `alladin_retail`

## Зона ответственности

- Управление заказами из админ-панели (смена статусов, отмена, добавление товаров, смена магазина)
- Выдача слотов доставки через HTTP API (пакет `cron/slots-api`)
- Модерация отзывов на магазины
- Публикация событий заказов в RabbitMQ для других сервисов

## Архитектура

### Общая БД
Все микросервисы docker используют одну БД `lastochka` (`DB_HOST=host.docker.internal`).

### RabbitMQ
- **Transformer**: `App\Transformers\OrderEventTransformer` — конвертация Order ↔ RabbitMQ событие
- **Routing key**: `order.*` (order.updated)
- **Outbox**: события сохраняются в БД перед отправкой (poll_interval=1s, batch_size=1000)
- **Только публикация**, входящих очередей нет

### Маппинг статусов заказа

| Константа | ID | RabbitMQ ключ |
|---|---|---|
| ORDER_CREATED | 1 | created |
| ORDER_PREPARING | 2 | assembling |
| ORDER_ASSEMBLED | 3 | assembled |
| ORDER_IN_DELIVERY | 4 | in_delivery |
| ORDER_FINISHED | 5 | delivered |
| ORDER_CANCELLED | 6 | cancelled |

## GraphQL API (`/graphql-purchase`)

### Queries (используются admin_front)
- `admin_orders_paginate` — список заказов
- `admin_orders_item` — детали заказа
- `admin_order_items_paginate` — товары в заказе
- `admin_shop_reviews_paginate` — отзывы на магазины
- `shops` — список магазинов

### Mutations (используются admin_front)
- `OrderGoToNextStep` — смена статуса заказа
- `OrderCancel` — отмена заказа
- `OrderShopUpdate` — смена магазина заказа
- `OrderMarkPaid` — ручная отметка оплаты наличного заказа (только payment_type_id=2, не отменённый; каскад на подзаказы, история payment_fixed; право `orders.mark_paid`)
- `ProductsAddToOrder` — добавление товаров в заказ
- `ReviewChangeStatus` — модерация отзывов

## Ключевые файлы

### Сервисы (`app/Services/`)
- `OrderCreatorService` — расчёт quantity/price для валидации остатков
- `ShopService` — работа с магазинами

### Конфиги (`config/`)
- `rabbitmq-events.php` — exchange, outbox, consumer settings
- `lighthouse.php` — GraphQL роутинг, guard, middleware

## Маршруты

### GraphQL
```
POST /graphql-purchase
```

### Slots API (пакет `cron/slots-api`)
```
GET/POST /api/v1/slots/available   — доступные слоты
GET/POST /api/v1/slots/shops       — доступные магазины по координатам
```

## Конфигурация

### Ключевые env-переменные
- `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD` — RabbitMQ

## Тесты

**Где запускать:** только локально, на серверах тесты не запускаются.

Тесты admin_back, delivery_back и purchase_back ходят в одну локальную MySQL-базу `courier_test` на 127.0.0.1:
она прибита в `phpunit.xml` каждого модуля, так что `.env` на прогон не влияет. Перед первым прогоном
и после новых миграций пересоздать её из admin_back:

```bash
php artisan testing:prepare-db   # в admin_back: migrate:fresh --seed в courier_test
./vendor/bin/phpunit             # здесь: полный сьют модуля
```

Тесты используют `DatabaseTransactions` (`RefreshDatabase` не использовать) и `MakesGraphQLRequests` для `/graphql-purchase`.

## Взаимодействие с другими модулями

- **admin_front** — вызывает GraphQL API (`/graphql-purchase`) для управления заказами и модерации отзывов
- **storefront_back** — вызывает Slots API (HTTP) для получения слотов доставки и доступных магазинов
- **admin_back, storefront_back** — потребляют RabbitMQ событие `order.updated`
