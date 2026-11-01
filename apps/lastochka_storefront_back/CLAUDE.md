# Aladdin Storefront Back

Бэкенд интернет-магазина (REST API для сайта и мобильного приложения). Мультивендорный магазин с интеграцией в систему доставки.

## Стек

- **PHP 8.4**, **Laravel 12**, **Sanctum** (auth)
- **Meilisearch v1.6** — полнотекстовый поиск товаров
- **RabbitMQ** — обмен событиями между модулями (пакет `multi_vendor_delivery_rabbitmq_package`, composer: `comrades/laravel-rabbitmq-events`)
- **Docker**: PHP 8.4-fpm + Nginx + Meilisearch, порт `8034`
- **Supervisor** — фоновые очереди
- **Scramble** — авто-генерация OpenAPI 3.0 документации

## Архитектура

Паттерн: **Action-Service-DTO**

```
app/
├── Actions/          # Бизнес-операции (18 action-классов)
│   ├── Address/      # CreateAddressAction, SetActiveAddressAction, UpdateAddressAction
│   ├── Auth/         # SendOtpAction, VerifyOtpAction
│   ├── Cart/         # AddToCart, ApplyPromocode, ClearCart, ClearStoreGroup, RemoveCartItem, RemovePromocode, UpdateCartItem
│   ├── Favorite/     # ToggleFavoriteAction
│   ├── Order/        # CancelOrder, CreateOrder, ReleaseProducts, ReserveProducts, SupplementOrder
│   └── Review/       # Create/Update ProductReview, Create/Update StoreReview
├── Services/         # Сервисный слой
│   ├── Auth/         # OtpService (OTP: 6 цифр, TTL 5 мин, max 3 попытки)
│   ├── Cart/         # CartCalculator (суммы, скидки)
│   ├── Delivery/     # DeliveryService, DeliverySystemClient, FakeDeliveryClient
│   ├── Payment/      # PaymentService
│   ├── Search/       # SearchService, MeilisearchClient, FakeSearchClient
│   ├── SlotsApi/     # SlotsApiClient, FakeSlotsApiClient
│   └── Store/        # AvailableStoreService
├── DTOs/             # Data Transfer Objects (6 DTO)
├── Enums/            # Gender, LinkType, OrderStatus, PaymentType, PromocodeType, ShippingMethod, UnitType
├── Events/           # OrderCreated, OrderCancelled, OrderSupplemented
├── Exceptions/       # 15 кастомных исключений
├── Http/
│   ├── Controllers/Api/V1/   # 15 контроллеров + Debug/RabbitDebugController
│   ├── Requests/             # 16 Form Request классов
│   ├── Resources/            # 37 API Resource класса
│   └── Middleware/            # RabbitDebugMiddleware
├── Models/           # 30 моделей
├── Observers/        # AddressObserver, ProductObserver (Meilisearch индексация)
├── Policies/         # Address, CartItem, Order, Review
├── Providers/        # AppServiceProvider (DI: fake-клиенты для тестов)
├── RabbitMQ/         # Трансформеры, условия, дебаг
│   ├── Conditions/   # CustomerHasPhoneCondition, ReviewHasUserCondition
│   ├── Debug/        # RabbitDebugService, LoggingEventConsumer/Publisher
│   └── Transformers/ # 13 трансформеров (Banner, Category, Customer, Order, Product, ...)
└── Console/Commands/ # ConsumeEventsCommand, RabbitCleanupLogsCommand, SearchReindexCommand, SeedTestDataCommand
```

## Модели (30)

User, Address, Cart, CartItem, CartItemSupplement, CartPromocode, Order, OrderAddress, OrderItem, OrderItemSupplement, OrderPromocode, OrderSupplementIdempotency, Product, ProductAttribute, ProductImage, ProductGroup, ProductGroupItem, ProductReview, Store, StoreReview, StoreSchedule, Supplement, SupplementValue, Banner, Story, StoryImage, Category, Favorite, OtpCode, Promocode, Promotion

## API (routes/api.php)

Все маршруты с префиксом `v1/`. Группы:

### Публичные
- `GET /stories` — сториз
- `GET /stores` — магазины
- `GET /stores/{store}/categories` — категории магазина
- `GET /stores/{store}/categories/{category}/filters` — фильтры каталога (price-range + select-атрибуты), `filter[code]=v1,v2` + `count_only=1` на `/products`
- `GET /products/{id}` — товар, похожие товары
- `GET /stores/{store}/banners` — баннеры
- `GET /product-groups` — группы товаров
- `GET /promotions` — акции
- `GET /stores/{store}/reviews`, `GET /products/{product}/reviews` — отзывы
- `GET /search/products`, `GET /search/suggestions` — поиск (throttle 100/мин)

### Защищенные (auth:sanctum)
- `POST /auth/phone-login`, `POST /auth/phone-confirm` — OTP авторизация
- `GET|PUT /auth/profile`, `POST /auth/logout`
- `GET /stores/{store}/delivery-slots` — слоты доставки
- CRUD `/addresses` — адреса пользователя
- `/favorites` — избранное
- CRUD `/cart` — корзина, промокоды
- `/orders` — заказы (создание throttle 5/мин, отмена 10/мин, дополнение 5/мин)
- CRUD отзывы на магазины и товары

### Debug (RabbitDebugMiddleware)
- `/debug/rabbit/*` — логи RabbitMQ сообщений

## RabbitMQ — обмен событиями

### Outbound (публикуем)
| Routing key | Модель | События | Условие |
|---|---|---|---|
| `customers` | User | created, updated | CustomerHasPhoneCondition |
| `store_reviews` | StoreReview | created, updated | ReviewHasUserCondition |
| `product_reviews` | ProductReview | created, updated | ReviewHasUserCondition |
| `orders` | Order | created (delay 5s) | ReviewHasUserCondition |

### Inbound (консумим)
`shops`, `categories`, `products`, `stories`, `merchant_sales` (promotions), `merchant_product_groups`, `merchant_banners`, `merchant_promocodes`, `store_reviews`, `product_reviews`, `orders`

Трансформеры в `app/RabbitMQ/Transformers/` преобразуют входящие события в модели.

### Документация по трансформерам

- [BaseEventTransformer](docs/transformers/base-event-transformer.md) — базовый класс: resolveEntityId, downloadImage, resolveStore
- [BannerEventTransformer](docs/transformers/banner-event-transformer.md) — баннеры (inbound), связь с товаром/категорией
- [CategoryEventTransformer](docs/transformers/category-event-transformer.md) — категории (inbound), иерархия через pending references, global_scope
- [CustomerEventTransformer](docs/transformers/customer-event-transformer.md) — пользователи (bidirectional), защита локальных данных от перезаписи
- [OrderEventTransformer](docs/transformers/order-event-transformer.md) — заказы (bidirectional), 4 кастомных события, маппинг статусов
- [ProductEventTransformer](docs/transformers/product-event-transformer.md) — товары (inbound), инверсия цен, КБЖУ, stock_changed
- [ProductGroupEventTransformer](docs/transformers/product-group-event-transformer.md) — группы товаров (inbound), синхронизация через ProductGroupItem
- [ProductReviewEventTransformer](docs/transformers/product-review-event-transformer.md) — отзывы на товары (bidirectional), пересчёт рейтинга с pessimistic lock
- [PromocodeEventTransformer](docs/transformers/promocode-event-transformer.md) — промокоды (inbound), поиск по code
- [PromotionEventTransformer](docs/transformers/promotion-event-transformer.md) — акции (inbound), sync товаров через pivot
- [StoreEventTransformer](docs/transformers/store-event-transformer.md) — магазины (inbound), синхронизация расписания
- [StoreReviewEventTransformer](docs/transformers/store-review-event-transformer.md) — отзывы на магазины (bidirectional), пересчёт рейтинга
- [StoryEventTransformer](docs/transformers/story-event-transformer.md) — истории (inbound), thumbnail + images, нормализация link_type

## Ключевые бизнес-правила

### Заказы
- Идемпотентность через `idempotency_key`
- Резервирование товаров при создании (`ReserveProductsAction`)
- Статусы: `Pending → Assembling → Assembled → InDelivery → Delivered | Cancelled`
- Дополнение заказа (`SupplementOrderAction`) с отдельной идемпотентностью

### Доставка

Storefront_back общается с модулем `docker/purchase_back` через два HTTP API:

**1. Получение доступных магазинов** — `SlotsApiClient` → `AvailableStoreService`
```
Клиент: GET /v1/stores?addressId=5
  → StoreController → AvailableStoreService
    → SlotsApiClient: GET {SLOTS_API_BASE_URL}/api/v1/slots/shops?latitude=...&longitude=...
    ← Ответ: {shops: [{code, delivery_price, min_order_price}, ...]}
    → Маппинг кодов на локальные Store модели из БД
    → Кеш: 'available_stores:address:{id}', TTL = SLOTS_API_CACHE_TTL (по умолч. 1 час)
    → AddressObserver инвалидирует кеш при изменении координат адреса
```

**2. Получение слотов доставки** — `DeliverySystemClient` → `DeliveryService`
```
Клиент: GET /v1/stores/{store}/delivery-slots?addressId=5&date=2024-01-20
  → DeliveryController → CartProductsMapper (корзина пользователя по магазину) → DeliveryService
    → DeliverySystemClient: POST {DELIVERY_SYSTEM_URL}/api/v1/slots/available
      Body: {code, latitude, longitude, date, products: [{articul, count}]}
      (products — товары корзины в порциях; slots-api отсекает слоты,
       на которые сборка не успевает по shops_categories.time_to_take)
    ← Ответ: {slots: [{start, end}, ...], shipping_price, min_order_price, shipping_free_from}
    → Преобразование в DeliveryInfoData/DeliverySlotData (id слота = timestamp начала)
    → Кеш (fallback при недоступности API): 'delivery_slots:{storeCode}:{lat}:{lng}:{date}:{md5(products)}', TTL = 30 мин
```

**3. Валидация слота при создании заказа** — `DeliveryService::validateSlot()`
```
CreateOrderAction → DeliveryService::validateSlot(store, address, slotId, products)
  → Получает актуальные слоты через getDeliveryInfo()
  → Проверяет наличие slotId среди доступных
  → DeliverySlotUnavailableException если слот уже недоступен
```

**Конфигурация** (`config/services.php`):
- `delivery_system` — url, api_key (Bearer token), timeout (10 сек)
- `slots_api` — base_url, timeout (10 сек), cache_ttl (3600 сек)

**Отказоустойчивость**:
- HTTP retry: 1 повтор с задержкой 100ms (delivery) / 1000ms (slots, только на 5xx)
- Fallback: при `DeliverySystemException` — возврат данных из кеша, если есть
- `DeliveryZoneNotFoundException` (zone_not_found) — пробрасывается сразу, без fallback
- `SlotsApiClient` при ошибке возвращает пустой массив (graceful degradation)

**DI**: интерфейсы `DeliveryClientInterface` и `SlotsApiClientInterface` — в тестах подменяются на `FakeDeliveryClient` / `FakeSlotsApiClient` через `AppServiceProvider`

### Поиск
- Meilisearch: индексация через `ProductObserver`
- Кеширование результатов 30 сек
- Переиндексация: `php artisan search:reindex`

## Конфигурация

- `config/rabbitmq-events.php` — маппинг inbound/outbound событий
- `config/services.php` — delivery_system (url, api_key), slots_api (base_url, cache_ttl)
- `config/meilisearch.php` — индексы, searchable/filterable/sortable атрибуты
- `config/rabbit-debug.php` — RabbitMQ debug dashboard

## Тестирование

43 теста: `tests/Feature/` (21 API + 9 RabbitMQ + 4 Debug) и `tests/Unit/` (4 DTO/Service).

При тестировании `AppServiceProvider` автоматически подставляет fake-клиенты:
- `FakeDeliveryClient` вместо `DeliverySystemClient`
- `FakeSearchClient` вместо `MeilisearchClient`
- `FakeSlotsApiClient` вместо `SlotsApiClient`

```bash
php artisan test              # все тесты
php artisan test --filter=Order  # тесты заказов
```

## Supervisor — обязательные фоновые процессы

Для работы обмена событиями через RabbitMQ на Supervisor постоянно должны быть запущены:

```bash
php artisan rabbitmq:publish-outbox   # публикация исходящих событий
php artisan rabbitmq:consume-all      # консьюмер входящих событий
php artisan queue:work                # обработка очередей Laravel
```
