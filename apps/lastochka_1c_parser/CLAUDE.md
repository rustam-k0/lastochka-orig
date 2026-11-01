# 1c_parser

Модуль двусторонней синхронизации между **1С:Торговое предприятие 7** и веб-магазином через протокол **CommerceML 2.x**.

## Стек

- **Laravel 12**, PHP 8.2+, **Pest**
- **mavsan/laravel-1c-protocol** (`packages/`) — protocol обмена CommerceML, Basic Auth, ZIP
- **multi_vendor_delivery_rabbitmq_package** (composer: `comrades/laravel-rabbitmq-events`, `packages/`) — RabbitMQ events, outbox, consumers
- **maatwebsite/excel** — Excel экспорт

## Структура проекта

```
app/
├── Console/Commands/        # 1c:import, 1c:export-orders, rabbitmq:consume-orders и др.
├── Exports/                 # ProductsExport, CategoriesExport (Excel)
├── Http/Middleware/          # ProcessOrdersAfterUnzip
├── Jobs/                    # ImportCatalogJob, ImportOffersJob, ImportRestsJob, ProcessImagesJob
├── Models/                  # Product, Category, Price, Stock, Image, Order, OrderItem, ExchangeLog
├── RabbitMQ/
│   ├── Consumers/           # OrderConsumer (orders_to_1c queue)
│   ├── DTO/                 # OrderEventDTO, CustomerDTO, OrderItemDTO
│   └── Transformers/        # ProductEventTransformer, CategoryEventTransformer, OrderEventTransformer
├── Services/
│   └── Exchange1C/          # CatalogImporter, OffersImporter, RestsImporter, OrderStatusSyncService,
│                            # OrderExportHandler, CatalogImportHandler, ProductWhitelist
│   └── OrderImportService.php
└── Providers/
```

## Модели

| Модель | Ключевые поля | Назначение |
|--------|---------------|------------|
| **Product** | external_id (GUID 1С), sku, barcodes (JSON), attributes (JSON), is_active | Товар |
| **Category** | external_id, parent_id (self-ref), name, sort | Категория (дерево) |
| **Price** | product_id, price_type, value (decimal:2), discount_value (decimal:2, nullable), currency | Цена товара |
| **Stock** | product_id, warehouse_id, warehouse_name, quantity (decimal:3) | Остаток на складе |
| **Image** | product_id, path, url, is_main, sort | Изображение товара |
| **Order** | shop_order_id, external_id (GUID 1С), number, status, total, one_c_response (JSON) | Заказ |
| **OrderItem** | order_id, product_id, external_id, name, quantity, price, total | Позиция заказа |
| **ExchangeLog** | type, status (started/success/error), message, records_count, started_at, finished_at | Лог обменов |

## Сервисы обмена (app/Services/Exchange1C/)

- **CatalogImporter** — парсит `import.xml`, создаёт/обновляет Category и Product, применяет whitelist
- **OffersImporter** — парсит `offers.xml` / `prices___*.xml`, обновляет Price и Stock, публикует события в RabbitMQ
- **RestsImporter** — парсит `rests___*.xml`, обновляет количество Stock
- **OrderStatusSyncService** — импортирует статусы из `orders___*.xml` от 1С
- **OrderExportHandler** — формирует XML с заказами для отправки в 1С (CommerceML 2.03)
- **CatalogImportHandler** — handler пакета mavsan, определяет тип файла и диспатчит Job'ы
- **ProductWhitelist** — фильтрация импорта по штрихкодам из CSV

## Jobs

| Job | Timeout | Tries | Backoff | Что делает |
|-----|---------|-------|---------|------------|
| ImportCatalogJob | 600s | 3 | 300s | Импорт каталога (CatalogImporter) |
| ImportOffersJob | 300s | 3 | 60s | Импорт цен/предложений (OffersImporter) |
| ImportRestsJob | 300s | 3 | 60s | Импорт остатков (RestsImporter) |
| ProcessImagesJob | 120s | 3 | — | Обработка изображений |

## RabbitMQ

**Исходящие** (exchange: `app_events`, topic):
`product.created`, `product.price_updated`, `product.stock_updated`, `category.created/updated`, `order.created/updated`

**Входящие** (очередь `orders_to_1c`, routing_key: `order.created`):
OrderConsumer — фильтрует по статусу `assembled`, создаёт Order через OrderImportService. Prefetch 10, Dead Letter Queue.

**Outbox:** таблица `event_outbox` для гарантированной доставки событий.

## Трансформеры (app/RabbitMQ/Transformers/)

Все реализуют `Comrades\RabbitMQEvents\Contracts\EventTransformer`.

### ProductEventTransformer

**toEvent()** — payload товара:
```
id             → product.external_id (GUID 1С)
shop_code/source → 'aladdin' / '1c' (из config)
sku            → product.sku, fallback "1C-{id}"
barcodes       → product.barcodes (JSON array)
title          → product.name
price          → розничная цена (Price где price_type = 'Розничная')
discount_price → акционная цена (Price.discount_value того же price_type), null = акции нет
image_url      → URL главного изображения (is_main=true) через Storage::url()
quantity       → сумма stocks.quantity по всем складам (min 0)
unit_type      → маппинг из attributes.unit (шт→piece, кг→kg, г→g, л→l, мл→ml, м→m, упак→pack)
manufacturer, country, brand, weight, nds → из attributes (requisites → properties → top-level)
attributes[]   → состав и КБЖУ как EAV {code, name, value}: composition/calories/proteins/fats/carbs
                 (buildNutritionAttributes). Парсятся из реквизитов 1С «Состав/Калорийность/Белки/
                 Жиры/Углеводы» в CatalogImporter; admin_back раскладывает в product_attributes
                 (category_type_id=100), storefront — в колонки. Пустые значения не шлются.
active         → product.is_active, ТОЛЬКО в created-событии (начальный статус).
                 В updated не публикуется: 1С не управляет витринной активностью,
                 безусловный active=true стирал ручную деактивацию в storefront
                 (зеркало LASTOCHKI-JAMI-11983 на стороне admin_back)
```

**toCustomEvent()** — кастомные события:

- **price_updated**: `{id, sku, price, old_price, price_type, currency, discount_price}` — при изменении базовой ИЛИ акционной цены в OffersImporter. `discount_price` — акционная цена из того же блока `<Цена>` (реквизит `<ЦенаЗаЕдиницуСоСкидкой>` рядом с `<ЦенаЗаЕдиницу>`), не отдельный тип цены. Отсутствие тега/невалидная скидка (`discount >= price` или `<= 0`) = акции нет → `null`
- **stock_updated**: `{id, sku, warehouse_id, warehouse_name, quantity, old_quantity}` — при изменении остатка

### OrderEventTransformer

**toEvent()** — payload заказа:
```
id → order.shop_order_id (fallback order.id), number, status,
customer_name/phone/email, delivery_address, comment, total
```

**fromEvent()** — входящий заказ из RabbitMQ (от storefront_back):
```
payload.id              → shop_order_id
payload.number          → number (или Order::generateNumber())
payload.client_info     → {surname, name, patronymic, phone, email} → "Фамилия Имя Отчество"
payload.grand_total     → total (fallback payload.total)
payload.delivery_address, payload.comment
→ status = STATUS_NEW, source = SOURCE_SHOP, received_at = now()
```

**validate()**: обязательны `id`, непустой `items[]`, наличие phone или email в `client_info`.

**afterCreate()** — создаёт OrderItem'ы:
- Ищет Product по `product_id` (internal id), затем по `name`
- `external_id` = product.external_id (GUID 1С) если товар найден
- Цена из `unit_price` (fallback `price`), итого из `line_total` (fallback `total`)

### CategoryEventTransformer

**toEvent()**: `{id → external_id, parent_id → parent.external_id, title → name, active}`
**fromEvent()**: `{external_id, name, is_active}`
**validate()**: обязательны `event_id, id, shop_code, source, title`

## Протокол обмена с 1С

**Endpoint:** `/1c-exchange` (Basic Auth). **Middleware:** ProcessOrdersAfterUnzip, StartSession.

**Входящие файлы** (storage/1cExchange/): `import.xml` (каталог), `offers.xml`/`prices___*.xml` (цены), `rests___*.xml` (остатки), `orders___*.xml` (статусы), `[images/]`

**Исходящие файлы** (storage/app/1c-export/): `orders_YYYY-MM-DD_HHmmss.xml` (CommerceML 2.03)

## Конфигурация

**config/exchange1c.php**: `server_url` (env `ONEC_SERVER_URL`), `exchange_login/password`, `price_types[]`, `default_warehouse_id`, `deactivate_missing_products`, `replace_images_on_update`, `batch_size` (500), `whitelist.enabled/path`

**config/protocolExchange1C.php**: `inputPath` (storage/1cExchange), `maxFileSize` (50MB), `catalogWorkModel` → CatalogImportHandler, `saleShareToXML` → OrderExportHandler

**config/rabbitmq-events.php**: `source` ('1c'), `shop_code` ('aladdin'), connection, exchange `app_events` (topic, durable), inbound queue `orders_to_1c`

## Логирование

- **Канал `1c-exchange`** — все операции обмена с 1С
- **Канал `rabbitmq-orders`** — импорт заказов из RabbitMQ
- **ExchangeLog модель** — структурированные логи в БД (type, status, records_count, duration)

## Supervisor (обязательные процессы)

Для работы обмена на сервере через Supervisor должны быть постоянно запущены:
- `php artisan rabbitmq:publish-outbox` — отправка событий из outbox в RabbitMQ
- `php artisan rabbitmq:consume-all` — приём входящих событий из RabbitMQ
- `php artisan queue:work` — обработка Job'ов (импорт каталога, цен, остатков, изображений)

## Scheduler

Ежедневно в 03:00 — `1c:cleanup --days=7 --logs` (очистка файлов обмена и старых логов).

## Тесты

`tests/Unit/`: ProductWhitelistTest, ProductEventTransformerTest
`tests/Feature/`: CatalogImportWhitelistTest, OrderImportTest, PriceUpdatedEventTest, PriceDiscountImportTest, ProductPriceFallbackTest, ExportProductsCommandTest

Прогонять `vendor/bin/pest` **по файлам/группам файлов**, не всем сьютом разом — полный прогон уже нестабилен на `main` (кросс-файловые побочные эффекты в общем sqlite `:memory:` процессе, включая fatal `Cannot redeclare makeProduct()` в ProblemProductsCollectorTest.php), см. `learnings.md` корня репо.
