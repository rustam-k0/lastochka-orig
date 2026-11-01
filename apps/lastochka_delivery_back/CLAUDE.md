# lastochka_delivery_back

Бэкенд для мобильного приложения курьеров и сборщиков (часть курьерской системы docker).

## Стек

- **PHP 8.1**, **Laravel 9.x**
- **GraphQL** — Lighthouse 5.x (основной API для мобильного приложения)
- **REST** — внутренние web routes для межсервисного взаимодействия
- **Auth** — Laravel Sanctum (GraphQL), системный токен (внутренний REST)
- **RBAC** — spatie/laravel-permission (роли: ADMIN, COLLECTOR, COURIER)
- **Push** — OneSignal
- **Очереди** — RabbitMQ через `comrades/laravel-rabbitmq-events` (Transactional Outbox)
- **RetailCRM** — синхронизация заказов через HTTP API

## Архитектура

### Dual API

1. **GraphQL** (`/graphql`) — для мобильного приложения, защищён Sanctum токеном. Директивы: `@guard`, `@can`
2. **REST** (`/backend-delivery/*`) — для вызовов из admin_back/purchase_back, защищён системным токеном (`config('services.system.token')`)

### Миграции

Миграций в этом модуле **нет**. Все миграции хранятся в `lastochka_store_back`. Этот сервис читает/пишет в общую БД.

### RabbitMQ

- Только **публикует** события (не потребляет входящих, `inbound_queues` пуст)
- Паттерн: **Transactional Outbox** — события сохраняются в таблицу outbox, затем публикуются worker'ом
- Пакет: `comrades/laravel-rabbitmq-events` (приватный GitLab)
- Exchange: `app_events` (type: topic, durable: true)
- Source: `env('RABBITMQ_SOURCE')` — идентификатор этого сервиса в payload
- Конфиг: `config/rabbitmq-events.php`
- Outbox worker: `php artisan rabbitmq:publish-outbox`

#### Публикуемое событие: `order.updated`

Единственная entity — `orders`. Триггерится автоматически на каждый Eloquent `updated` event модели `Order`.

**Конфигурация:**
```php
'orders' => [
    'enabled' => true,
    'model' => \App\Models\Order::class,
    'transformer' => \App\Transformers\OrderEventTransformer::class,
    'relations' => ['orderItems.product', 'clientInfo', 'shop', 'client'],
    'events' => ['updated'],
    'routing_key_prefix' => 'order',
    'exchange' => 'default',  // → app_events
]
```

**Routing key:** `order.updated`

**Когда публикуется:** при любом `$order->update(...)` или `$order->save()` (Eloquent updated event). Это означает — при смене статуса сборки, доставки, отмене, завершении и т.д. через GraphQL мутации и внутренний REST API.

#### Payload события (toEvent)

Трансформер `App\Transformers\OrderEventTransformer::toEvent()` формирует:

```json
{
  "event_id": "uuid",
  "event_timestamp": "2024-01-01T00:00:00",
  "id": "<order.uuid>",
  "shop_code": "<shop.code>",
  "source": "<RABBITMQ_SOURCE>",

  "status": "created|assembling|assembled|in_delivery|delivered|cancelled",
  "payment_type": "cash|card",
  "shipping_method": "courier|pickup|cdek|russian_post",
  "grand_total": 1500.00,
  "is_paid": true,

  "slot_start_date": "2024-01-01 10:00:00",
  "slot_end_date": "2024-01-01 12:00:00",
  "client_comment": "позвонить за 10 минут",

  "client_id": "<external_id из event_sync_metadata>",
  "client_source": "<source из event_sync_metadata>",

  "client_info": {
    "name": "Иван",
    "surname": "Иванов",
    "patronymic": null,
    "phone": "+79991234567",
    "email": "ivan@example.com"
  },

  "address": {
    "address": "ул. Пушкина, д. 1",
    "entrance": "2",
    "intercom": "123",
    "floor": "5",
    "flat": "42",
    "latitude": 55.7558,
    "longitude": 37.6173
  },

  "items": [
    {
      "id": "<external_id из event_sync_metadata>",
      "source": "<source>",
      "product_id": "<external_id товара>",
      "product_source": "<source товара>",
      "product_name": "Молоко 1л",
      "quantity": 2,
      "unit_price": 89.90,
      "unit_price_effective": 79.90,
      "line_subtotal": 179.80,
      "line_discount_total": 0,
      "line_total": 179.80
    }
  ],

  "created_at": "...",
  "updated_at": "...",
  "deleted_at": null
}
```

#### Маппинги (internal ↔ external)

**Статусы:**

| External (RabbitMQ) | Internal (Status::) | ID |
|---|---|---|
| `created` | ORDER_CREATED | 1 |
| `assembling` | ORDER_PREPARING | 2 |
| `assembled` | ORDER_ASSEMBLED | 3 |
| `in_delivery` | ORDER_IN_DELIVERY | 4 |
| `delivered` | ORDER_FINISHED | 5 |
| `cancelled` | ORDER_CANCELLED | 6 |

**Способы оплаты:**

| External | Internal (Type::) |
|---|---|
| `cash` | PAYMENT_CASH |
| `card` | PAYMENT_CARD |

**Способы доставки:**

| External | Internal (Type::) |
|---|---|
| `courier` | DELIVERY_COURIER |
| `pickup` | DELIVERY_PICKUP |
| `cdek` | DELIVERY_CDEK |
| `russian_post` | DELIVERY_RUSSIAN_POST |

#### Трансформер: OrderEventTransformer

Файл: `app/Transformers/OrderEventTransformer.php`

Реализует `EventTransformer`. Traits: `ValidatesEventData`, `HasEventHandlerHooks`, `ResolvesExternalReferences`, `ResolvesShopCode`.

**Ключевые методы:**

| Метод | Назначение |
|---|---|
| `toEvent(Order)` | Сериализация Order → payload для публикации в RabbitMQ |
| `fromEvent(array)` | Десериализация входящего события → атрибуты Order (для потребления, хотя delivery_back не потребляет) |
| `beforeCreate(eventData, transformedData)` | Хук: находит/создаёт Client перед созданием заказа |
| `afterCreate(Order, eventData)` | Хук: создаёт OrderItems, ClientOrderInfo, pending references |
| `afterUpdate(Order, eventData)` | Хук: синхронизирует OrderItems (создаёт/обновляет/удаляет), обновляет ClientOrderInfo |

**External references (event_sync_metadata):**
- `client_id` + `client_source` → локальный `Client` (shop_code_scoped: false — клиенты общие для агрегатора)
- `product_id` + `product_source` → локальный `Product` (для items, в рамках shop_code)
- `item.id` + `item.source` → локальный `OrderItem`

**Fallback при отсутствии данных:**
- Если Client не найден и не создан → `client_id = 1` (системный клиент) + pending reference
- Если Product не найден по metadata → поиск по `title` → создание нового Product → `product_id = 1` (fallback) + pending reference
- При создании Product автоматически создаётся Price

#### Outbox настройки

```
poll_interval: 1 сек
batch_size: 1000
memory_limit: 128 MB
retention_days: 7
fallback_queue: database (rabbitmq_retry)
retry: 3 попытки с backoff [60, 300, 900] сек
```

### RetailCRM

- `App\Services\RetailCrmService` — HTTP-клиент
- `App\Services\RetailCrmSyncService` — синхронизация заказов
- `App\Observers\OrderObserver` — при изменении `status_id` синхронизирует статус в RetailCRM
- Конфиг: `config/retailcrm.php`

## Структура кода

```
app/
├── Console/Commands/         # Artisan-команды
│   ├── FinishShifts.php      # command:finishShifts — завершает незакрытые смены
│   └── UpdateOrderStatusCommand.php  # order:update-status {retail_crm_id} {status}
├── Filters/                  # QueryFilter, AssemblyFilter, AssemblyItemFilter, ProductFilter
├── GraphQL/
│   ├── Mutations/
│   │   ├── Assembly/         # AssemblyCancel, AssemblyFinish, AssemblyStartCooking, Product*
│   │   ├── Delivery/         # DeliveryStart, DeliveryTakeOrder, DeliveryArrived, DeliveryFinish, DeliveryOrderChangePriority
│   │   ├── Order/            # OrderPrepare, OrderCollectorUpdate, OrderCourierUpdate
│   │   └── User/             # UserLogin, UserLogout, UserConfirmSelfie
│   ├── Queries/              # Collector, Courier
│   └── Validators/           # Валидатор на каждую мутацию
├── Helpers/                  # FakerHelper, Upload
├── Http/Controllers/         # OrderController (внутренний REST), UserController, ImageController, OneSignalController
├── Models/                   # Order, Assembly, Delivery, User, Product, Shop, Shift и др.
│   └── Scopes/NotFinished.php  # Global scope: finished_at IS NULL
├── Observers/OrderObserver.php
├── Policies/AdminPolicy.php  # onShift, driverOnShift, onlyAdmin
├── Rules/                    # Кастомные правила валидации
├── Services/                 # ShopService, RetailCrmService, RetailCrmSyncService, PhoneFormatter
├── Traits/                   # ModelTools, HasImage, HasDocument
└── Transformers/OrderEventTransformer.php  # RabbitMQ event transformer

graphql/
├── schema.graphql            # Точка входа
├── inputs.graphql
├── types.graphql
└── Delivery/                 # Схемы по доменам: User, Order, Assembly, Delivery, Product, ExplainReason

routes/
├── web.php                   # REST: /backend-delivery/* (межсервисный API)
└── api.php                   # Пуст — всё через GraphQL
```

## Ключевые модели

| Модель | Назначение |
|---|---|
| `Order` | Заказ. Центральная сущность |
| `Assembly` | Сборка заказа (GlobalScope: NotFinished) |
| `AssemblyItem` | Позиция сборки |
| `Delivery` | Доставка курьера (GlobalScope: NotFinished, MAX_ORDERS_COUNT = 10) |
| `DeliveryOrder` | Связь Delivery ↔ Order |
| `User` | Курьер/сборщик (HasApiTokens, HasRoles) |
| `Shift` | Смена пользователя (GlobalScope: NotFinished) |
| `Shop` | Магазин |
| `Product` | Товар |
| `RetailCrmOrder` | Связь заказа с RetailCRM |
| `Status` | Справочник статусов (константы ORDER_*, PRODUCT_*, DELIVERY_*) |
| `Type` | Справочник типов (константы UNIT_*, PRICE_*, PAYMENT_*, DELIVERY_*, SHOP_TYPE_*) |
| `Role` | Роли: ADMIN, COLLECTOR, COURIER |

## Паттерны

- **Transactional Outbox** — события сохраняются в БД, затем публикуются в RabbitMQ
- **Observer** — `OrderObserver` слушает изменение статуса для синхронизации с RetailCRM
- **Global Scope** — `NotFinished` на Assembly, Delivery, Shift (автоматически скрывает завершённые)
- **Service** — бизнес-логика в сервисах (ShopService — singleton, RetailCrmService, RetailCrmSyncService)
- **Transformer** — OrderEventTransformer для преобразования данных internal ↔ external
- **Filter/Sorter** — динамические фильтры через Eloquent scopes (резолвятся по имени модели через ModelTools trait)
- **Policy** — AdminPolicy с проверками onShift, driverOnShift
- **Validator per Mutation** — каждая GraphQL мутация имеет отдельный класс-валидатор

## Внутренний REST API (web routes)

Все endpoints защищены системным токеном. Prefix: `/backend-delivery/`

| Метод | Endpoint | Контроллер | Назначение |
|---|---|---|---|
| POST | `/order/prepare` | OrderController@prepare | Начать сборку |
| POST | `/order/assembly` | OrderController@assembly | Обновить сборку |
| POST | `/order/assembly-finish` | OrderController@assemblyFinish | Завершить сборку |
| POST | `/order/delivery` | OrderController@delivery | Начать доставку |
| POST | `/order/finish` | OrderController@finish | Завершить заказ |
| POST | `/order/shop/change` | OrderController@shopChange | Сменить магазин |
| POST | `/order/cancel` | OrderController@cancel | Отменить заказ |
| POST | `/order/product/add` | OrderController@productAdd | Добавить товар |
| POST | `/user/delete` | UserController@delete | Удалить пользователя |
| POST | `/notification` | OneSignalController@notifyOrderStatus | Push-уведомление |

## Docker

- **Порт:** 8031
- **Сеть:** `alladin_retail` (external, общая для всех сервисов)
- **Supervisor:** nginx + php8.1-fpm
- **Dockerfile:** `_docker/Dockerfile` (PHP 8.1, Composer)

## Тесты

**Где запускать:** только локально, на серверах тесты не запускаются.

Тесты admin_back, delivery_back и purchase_back ходят в одну локальную MySQL-базу `courier_test` на 127.0.0.1:
она прибита в `phpunit.xml` каждого модуля, так что `.env` на прогон не влияет. Перед первым прогоном
и после новых миграций пересоздать её из admin_back:

```bash
php artisan testing:prepare-db   # в admin_back: migrate:fresh --seed в courier_test
./vendor/bin/phpunit             # здесь: полный сьют модуля
```

Тесты используют `DatabaseTransactions` (`RefreshDatabase` не использовать) и `MakesGraphQLRequests` для `/graphql-delivery`.

## Ключевые переменные окружения

| Переменная | Назначение |
|---|---|
| `APP_TOKEN` / `DELIVERY_TOKEN` | Системный токен для внутреннего REST API |
| `LIGHTHOUSE_CACHE_ENABLE` | Кеширование GraphQL схемы |
| `ENABLE_FAKER` | Генерация тестовых данных |
| `ONESIGNAL_ID`, `ONESIGNAL_REST_API_KEY` | Push-уведомления |
| `RETAILCRM_URL`, `RETAILCRM_API_TOKEN` | RetailCRM интеграция |
| `RABBITMQ_*` | Настройки RabbitMQ |

## Известные проблемы

- Дублирующие файлы: `app/Rules/ChechUserCredentials.php` и `app/Rules/CheckUserCredentials.php` (опечатка)
- В `supervisor.conf` нет `rabbitmq:publish-outbox` worker — для production нужно добавить
- В `supervisor.conf` нет queue worker
