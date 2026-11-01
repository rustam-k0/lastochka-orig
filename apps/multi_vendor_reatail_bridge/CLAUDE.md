# multi_vendor_reatail_bridge

Двунаправленный мост синхронизации между Bagisto/DeliverySystem и RetailCRM.
Laravel 12, PHP 8.2+.

## Три потока данных

### 1. RabbitMQ → RetailCRM (входящие события от Bagisto)
```
RabbitMQ → ConsumeCommand (rabbitmq:consume {entity})
  → BaseConsumerHandler::handle()
    → validateStructure() → loopback check → idempotency check
    → syncToRetailCRM() (в подклассе)
```

### 2. RetailCRM → RabbitMQ (вебхуки)
```
POST /api/webhooks/retailcrm → WebhookController
  → shouldSkipEvent() (anti-cycle cache)
  → isSameState() (deduplication)
  → OutboxService::dispatch() → EventOutbox → OutboxPublisher → RabbitMQ
```

### 3. RetailCRM → RabbitMQ (плановый поллинг)
```
Scheduler → ProcessRetailPoll Job (Cache::lock 10 мин)
  → retail:poll {entity}
    → RetailCRMService::get*()
    → EntityHashService::hasChanged()
    → OutboxService::dispatch() → EventOutbox → RabbitMQ
```

## Ключевая структура

```
app/
├── Console/Commands/
│   ├── RabbitMQ/
│   │   ├── ConsumeCommand.php          # rabbitmq:consume {entity}
│   │   └── PublishOutboxCommand.php    # rb:publish-outbox
│   └── RetailCRM/
│       └── PollEntityCommand.php       # retail:poll {entity}
├── Http/Controllers/RetailCRM/
│   └── WebhookController.php           # POST /api/webhooks/retailcrm
├── Jobs/
│   └── ProcessRetailPoll.php
├── Models/
│   ├── Mapping/                        # Legacy mapping-таблицы (Order, Customer, Product, Category, Shop, User, Address)
│   └── System/
│       ├── EventOutbox.php             # Транзакционный outbox
│       ├── EventSyncMetadata.php       # Главная mapping-таблица
│       ├── EntityHash.php              # Хеши для change detection
│       └── SyncState.php              # Время последней синхронизации
├── Services/
│   ├── Consumers/                      # → docs/consumers/
│   │   ├── BaseConsumerHandler.php     # Абстрактный: валидация, loopback, idempotency
│   │   ├── ProductConsumerHandler.php
│   │   ├── CategoryConsumerHandler.php
│   │   ├── OrderConsumerHandler.php
│   │   ├── CustomerConsumerHandler.php
│   │   ├── UserConsumerHandler.php
│   │   ├── ShopConsumerHandler.php
│   │   └── DeliveryAddressConsumerHandler.php
│   ├── Converters/                     # → docs/converters/
│   │   ├── BaseConverter.php
│   │   ├── ProductConverter.php
│   │   ├── CategoryConverter.php
│   │   ├── OrderConverter.php
│   │   ├── CustomerConverter.php
│   │   ├── ShopConverter.php
│   │   └── UserConverter.php
│   ├── Hash/EntityHashService.php      # MD5-хеш для change detection
│   ├── Idempotency/IdempotencyService.php
│   ├── Outbox/
│   │   ├── OutboxService.php           # Запись событий в outbox
│   │   └── OutboxPublisher.php         # Публикация из outbox в RabbitMQ
│   ├── RabbitMQ/
│   │   ├── Consumer.php
│   │   ├── Publisher.php
│   │   └── RabbitMQConnection.php
│   └── RetailCRM/
│       ├── RetailCRMService.php        # Основной сервис CRM API v5
│       ├── RetailCRMApiService.php     # Legacy HTTP клиент
│       └── Http/
│           ├── RetailCrmClient.php     # HTTP клиент (X-API-KEY, retry 3x500ms)
│           └── Response.php
├── Observers/
│   └── EventOutboxObserver.php         # Auto-creates metadata для RetailCRM событий
├── Providers/
│   ├── RabbitMQServiceProvider.php
│   └── RetailCRMServiceProvider.php
└── Support/
    └── ShopCodeResolver.php            # Резолв shop_code через fallback-цепочку
```

## Ключевые таблицы БД

### `event_sync_metadata` — главная mapping-таблица
Уникальный индекс: `(entity_type, source, external_id, shop_code)`
Поля: `entity_type`, `source`, `external_id`, `shop_code`, `entity_id` (RetailCRM ID), `last_event_id`, `last_event_timestamp`, `locked_at`, `data` (JSON)

### `event_outbox` — транзакционный outbox
Поля: `event_id` (UUID), `entity_type`, `event_type`, `routing_key`, `payload` (JSON), `status`, `retries`, `published_at` (NULL = не опубликовано)

### `entity_hashes` — change detection
Уникальный индекс: `(entity_type, entity_id, shop_code)`. Хранит MD5 конвертированных данных.

### `sync_states` — время последней синхронизации
Уникальный ключ: `entity`. Поле: `last_sync_at`.

### Mapping-таблицы (legacy)
`order_mappings`, `customer_mappings`, `product_mappings`, `category_mappings`, `shop_mappings`, `user_mappings`, `address_mappings` — используются в WebhookController.

## Паттерны

### Предотвращение циклов
- **Consumer**: проверка `event_source === config('retailcrm.source')` → ACK без обработки
- **Webhook**: Cache-ключ `sync_order_{id}` ставится перед отправкой в CRM, проверяется при получении вебхука

### Идемпотентность
Проверка `last_event_id` (UUID) и `last_event_timestamp` в EventSyncMetadata

### Транзакционный Outbox
События → `event_outbox` (в DB-транзакции) → `OutboxPublisher` → RabbitMQ. Гарантирует доставку при сбоях.

### Hash-based Change Detection
MD5(JSON без `updated_at`). После sync — GET из CRM, конвертация, сохранение хеша реальных данных CRM.

### Dead Letter Queue
Consumer проверяет `x-death` header: >= 5 смертей → reject(false)

### Мультивендорность (shop_code)
Цепочка: `$data['shop_code']` → `config('retailcrm.shop_code')` → `RetailCRM getMainSite()` (кеш 1ч) → `'default'`

## Консольные команды

| Команда | Описание |
|---|---|
| `rabbitmq:consume {entity}` | Consumer для: product, category, customer, order, user, delivery_address, shop |
| `rb:publish-outbox {entity?} {--batch=100} {--loop} {--sleep=1}` | Публикация из outbox в RabbitMQ |
| `retail:poll {entity} {--full}` | Поллинг из RetailCRM: products, categories, shops, collectors, couriers, all |

## Планировщик (routes/console.php)

- `ProcessRetailPoll('products')` — каждые 5 минут
- `ProcessRetailPoll('categories')` — каждые 30 минут
- `ProcessRetailPoll('shops')` — каждый час

## Маппинг статусов заказов

### Исходящие (Система → CRM)
created→new, assembling→assembling, assembled→assembling-complete, in_delivery→delivering, delivered→complete, cancelled→cancel-other, returned→return

### Входящие (CRM → Система)
30+ статусов CRM → 7 системных (created, assembling, assembled, in_delivery, delivered, cancelled, returned)

## Конфигурация

### config/retailcrm.php
`RETAILCRM_URL`, `RETAILCRM_API_KEY`, `RETAILCRM_SHOP_CODE`, `RETAILCRM_WAREHOUSE_CODE`, `RETAILCRM_DEFAULT_CATALOG_ID`, `RETAILCRM_COLLECTORS_DICTIONARY`

### config/rabbitmq.php
Exchange: `retailbridge` (topic, env: `RB_EXCHANGE_NAME`). Очереди: `retailbridge.{products,categories,orders,customers,delivery_addresses,shops}`

### config/rabbitmq-events.php
`RB_EVENT_SOURCE`, `RB_EXCHANGE_NAME`, prefetch_count=1, restart_after=1000, memory_limit=128MB, outbox.batch_size=200

## Деплой
docker-compose: nginx (порт 8044) + retail_app, сеть `alladin_retail` (external)

## Supervisor (обязательные процессы)

Для работы обмена данными все 4 команды должны быть постоянно запущены через Supervisor:

| Команда | Назначение |
|---|---|
| `php artisan queue:work` | Обработка Laravel-очередей (ProcessRetailPoll jobs) |
| `php artisan rb:publish-outbox --loop` | Публикация событий из outbox в RabbitMQ (бесконечный цикл) |
| `php artisan rabbitmq:consume-all` | Потребление событий из всех RabbitMQ-очередей |
| `php artisan schedule:work` | Запуск планировщика (поллинг products/categories/shops по расписанию) |

Без любой из этих команд соответствующая часть обмена перестанет работать.

## Документация Consumers (подробно)

| Consumer | Документ | Краткое описание |
|---|---|---|
| BaseConsumerHandler | [docs/consumers/BaseConsumerHandler.md](docs/consumers/BaseConsumerHandler.md) | Абстрактный: SELECT-first metadata, loopback prevention, idempotency, findRetailCrmId с fallback |
| ProductConsumerHandler | [docs/consumers/ProductConsumerHandler.md](docs/consumers/ProductConsumerHandler.md) | Hash dedup, поиск по externalId/article, защита изображений, duplicateCategoryForSite, updatePrices/Inventories |
| CategoryConsumerHandler | [docs/consumers/CategoryConsumerHandler.md](docs/consumers/CategoryConsumerHandler.md) | Auto-create site, fallback CREATE при 404, retry без parentId |
| OrderConsumerHandler | [docs/consumers/OrderConsumerHandler.md](docs/consumers/OrderConsumerHandler.md) | processCustomer (4-этапный поиск), processOrderItems (batch offer ID), защита от дублей |
| CustomerConsumerHandler | [docs/consumers/CustomerConsumerHandler.md](docs/consumers/CustomerConsumerHandler.md) | findExistingCustomer по phone (fuzzy) → email, дедупликация |
| UserConsumerHandler | [docs/consumers/UserConsumerHandler.md](docs/consumers/UserConsumerHandler.md) | Роутинг по role: courier (API) / collector (кастомный словарь), ensureCollectorsDictionary |
| ShopConsumerHandler | [docs/consumers/ShopConsumerHandler.md](docs/consumers/ShopConsumerHandler.md) | editSite (upsert), shop.deleted только лог, entity_id = shop_code, extended data в metadata |
| DeliveryAddressConsumerHandler | [docs/consumers/DeliveryAddressConsumerHandler.md](docs/consumers/DeliveryAddressConsumerHandler.md) | Обновляет address клиента в CRM, entity_id = customerRetailId |

## Документация Converters (подробно)

| Converter | Документ | Краткое описание |
|---|---|---|
| BaseConverter | [docs/converters/BaseConverter.md](docs/converters/BaseConverter.md) | Абстрактный: toRetailCRM/fromRetailCRM, resolveSourceAndExternalId, ResolvesShopCode trait |
| ProductConverter | [docs/converters/ProductConverter.md](docs/converters/ProductConverter.md) | Offers с prices/barcodes/weight, извлечение цены из offers[0].prices, резолв категории через metadata |
| CategoryConverter | [docs/converters/CategoryConverter.md](docs/converters/CategoryConverter.md) | Product groups: name/externalId/active, резолв parent через metadata |
| OrderConverter | [docs/converters/OrderConverter.md](docs/converters/OrderConverter.md) | Самый сложный: статусы, доставка, адрес, слоты, items, клиент, getProductExternalIdByOfferId |
| CustomerConverter | [docs/converters/CustomerConverter.md](docs/converters/CustomerConverter.md) | ФИО, phones[], sex/presumableSex, birthday |
| ShopConverter | [docs/converters/ShopConverter.md](docs/converters/ShopConverter.md) | Sites: code/name/url/countryIso, sanitizeShopCode, extractExtendedData |
| UserConverter | [docs/converters/UserConverter.md](docs/converters/UserConverter.md) | Роутинг: toCourierFormat (API) / toCollectorFormat (справочник), парсинг ФИО из name |
