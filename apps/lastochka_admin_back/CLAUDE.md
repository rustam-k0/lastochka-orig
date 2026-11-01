# lastochka_admin_back

Административный бэкенд курьерской системы / мультивендорного интернет-магазина.
Микросервис на **Laravel 9 + PHP 8.1** с **GraphQL API** (Lighthouse) и **RabbitMQ** обменом данных.

## Назначение

Централизованное управление:
- Товарами, категориями, брендами, атрибутами
- Магазинами и их рабочими часами
- Заказами и клиентами
- Промоакциями (баннеры, промокоды, скидки, группы товаров)
- Отзывами (мерчант + товарные)
- Историями (Stories)
- Пользователями и ролями (RBAC)

Все изменения синхронизируются на другие микросервисы через RabbitMQ (Transactional Outbox паттерн).

## Стек

| Технология | Версия / Пакет |
|---|---|
| PHP | 8.1 |
| Laravel | 9.x |
| GraphQL | Lighthouse 5.31 |
| Auth | Sanctum 2.11 |
| RBAC | Spatie Permission 5.4 |
| RabbitMQ | comrades/laravel-rabbitmq-events (dev-main) |
| Images | Intervention Image 2.7 |
| Storage | S3 (league/flysystem-aws-s3-v3) |
| Phone | propaganistas/laravel-phone 5.0 |
| Docker | nginx + php-fpm 8.1, порт 8033 |

## Структура проекта

```
app/
├── Console/Commands/     # Artisan-команды (UserCreate, CheckRemains, GeneratorStart)
├── Contracts/            # Интерфейсы (PhoneSystem, SmsService)
├── Events/               # ProductUpdated
├── Filters/              # QueryFilter (базовый), ProductFilter, UserFilter, ClientFilter и др.
├── GraphQL/
│   ├── Queries/          # ShopProductsForGroupQuery, ShopProductsForSaleQuery, GetShopCategories
│   ├── Mutations/        # 28+ мутаций (Product/, Category/, Shop/, MerchantBanner/ и др.)
│   └── Validators/
├── Helpers/
├── Http/Controllers/     # ImageController (GET /action/img/{id})
├── Jobs/
├── Listeners/            # UpdateProductFillPercent
├── Mail/                 # EmailUpdated
├── Models/               # 58 моделей
├── Providers/
├── Rules/
├── Services/             # ImageService, ShopService, StoryService, MerchantBannerService и др.
├── Sorters/
├── Traits/               # HasDocument, HasImage, ModelTools
└── Transformers/         # 14 RabbitMQ-трансформеров (ProductEventTransformer, OrderEventTransformer и др.)
```

## API

**Тип:** GraphQL
**Endpoint:** `/graphql-admin` (настроен в `config/lighthouse.php`)
**Playground:** доступен через `mll-lab/laravel-graphql-playground`

REST-маршруты минимальны — только `GET /action/img/{id}` для отдачи изображений.

### GraphQL схемы

Все схемы находятся в `graphql/` (~140 файлов):
- `schema.graphql` — главная схема
- `types.graphql` — базовые типы
- `inputs.graphql` — input-типы
- `admin/queries.graphql` и `admin/mutations.graphql` — root queries/mutations
- `Product/`, `Client/`, `User/` — доменные модули

## RabbitMQ интеграция

**Конфиг:** `config/rabbitmq-events.php`
**Exchange:** `app_events` (topic)
**Паттерн:** Transactional Outbox
**Сущности:** 21+ (categories, products, orders, customers, shops, stories и др.)

Каждая сущность имеет свой трансформер в `app/Transformers/` — они формируют payload для RabbitMQ событий.

**Связанные модули через RabbitMQ:**
- storefront_back
- delivery_back
- purchase_back
- 1c_parser
- multi_vendor_retail_bridge

## База данных

- **Основные миграции** хранятся в модуле `store_back` (не здесь!)
- Локальные миграции в `database/migrations/` — дополнительные таблицы (stories, merchant_sales, merchant_banners, merchant_promocodes, merchant_reviews, product_reviews и др.)
- Фабрики: 9 штук (Admin, Product, Shop, MerchantBanner и др.)
- Сидеры: AdminSeeder, ApiRoleSeeder, UserRolesSeeder и др.

## Тесты

**Расположение:** `tests/`
**Фреймворк:** PHPUnit 9.5
**Где запускать:** только локально, на серверах тесты не запускаются.

Тесты admin_back, delivery_back и purchase_back ходят в одну локальную MySQL-базу `courier_test` на 127.0.0.1:
она прибита в `phpunit.xml` каждого модуля, так что `.env` на прогон не влияет. Перед первым прогоном
и после новых миграций пересоздать её из admin_back:

```bash
php artisan testing:prepare-db   # migrate:fresh --seed в courier_test
./vendor/bin/phpunit             # полный сьют модуля
```

Тесты используют:
- `DatabaseTransactions` — откат БД после каждого теста (`RefreshDatabase` не использовать)
- `MakesGraphQLRequests` — тестирование GraphQL через Lighthouse
- `$this->adminUser()` из `Tests\TestCase` — админ из `AdminSeeder` (не `User::firstOrFail()`)

## Важные паттерны и соглашения

- **GraphQL мутации** — каждая мутация в отдельном классе (`app/GraphQL/Mutations/`), схема в отдельном `.graphql` файле
- **Фильтры** — наследуются от `QueryFilter`, используют scope `filter` в моделях
- **Traits** — `HasImage` для моделей с изображениями, `HasDocument` для документов, `ModelTools` для общих утилит
- **Сервисы** — бизнес-логика выносится в `app/Services/`
- **Удаление** — через универсальную мутацию `EntityDelete`

## Трансформеры (app/Transformers/)

Все трансформеры реализуют интерфейс `Comrades\RabbitMQEvents\Contracts\EventTransformer` и обеспечивают двустороннюю конвертацию данных между локальными моделями и RabbitMQ событиями.

### Общие принципы

- **toEvent()** — конвертирует локальную модель в payload для публикации в RabbitMQ
- **fromEvent()** — конвертирует входящее событие в атрибуты модели для create/update
- **findExistingEntity()** — дедупликация: поиск существующей сущности перед созданием
- **beforeCreate()** — хук перед созданием (разрешение внешних ссылок, генерация паролей и т.д.)
- **afterCreate()/afterUpdate()** — хуки после сохранения (связанные данные, цены, остатки, категории)
- **process*Event()** — обработчики кастомных событий (price_updated, stock_updated и т.д.)

### Используемые трейты

| Трейт | Назначение |
|---|---|
| `ValidatesEventData` | Валидация обязательных полей (`$requiredFields`) |
| `HasEventHandlerHooks` | Хуки before/afterCreate, before/afterUpdate |
| `HasCustomEventHandlers` | Обработка кастомных событий (process*Event) |
| `DownloadsExternalImages` | Скачивание изображений по URL из события |
| `ResolvesExternalReferences` | Разрешение внешних ID через `EventSyncMetadata` |
| `ResolvesShopCode` | Разрешение shop_code → локальный shop_id |

### External References (внешние ссылки)

Ключевой механизм синхронизации. Каждый трансформер объявляет `$externalReferences` — маппинг полей, которые содержат внешние ID. При получении события трейт `ResolvesExternalReferences` ищет соответствие через таблицу `event_sync_metadata` (entity_type, source, external_id → entity_id).

При отправке события (toEvent) — обратный процесс: ищется metadata и подставляется external_id + source.

### Race condition защита

Все трансформеры используют защиту от race conditions:
- `firstOrCreate` с catch `Duplicate entry` и повторным поиском
- `lockForUpdate()` в транзакциях для цен и остатков
- `withoutEvents()` для предотвращения рекурсивных событий

### Перечень трансформеров

| Трансформер | Модель | Направление | Подробная документация |
|---|---|---|---|
| **ProductEventTransformer** | Product | Двусторонний | [docs/transformers/product.md](docs/transformers/product.md) |
| **OrderEventTransformer** | Order | Двусторонний | [docs/transformers/order.md](docs/transformers/order.md) |
| **CategoryEventTransformer** | Category | Двусторонний | [docs/transformers/category.md](docs/transformers/category.md) |
| **ClientEventTransformer** | Client | Двусторонний | [docs/transformers/client.md](docs/transformers/client.md) |
| **ClientAddressEventTransformer** | DeliveryAddress | Двусторонний | [docs/transformers/client-address.md](docs/transformers/client-address.md) |
| **ShopEventTransformer** | Shop | Двусторонний | [docs/transformers/shop.md](docs/transformers/shop.md) |
| **UserEventTransformer** | User | Двусторонний | [docs/transformers/user.md](docs/transformers/user.md) |
| **StoryEventTransformer** | Story | Исходящий | [docs/transformers/story.md](docs/transformers/story.md) |
| **MerchantBannerEventTransformer** | MerchantBanner | Исходящий | [docs/transformers/merchant-banner.md](docs/transformers/merchant-banner.md) |
| **MerchantProductGroupEventTransformer** | MerchantProductGroup | Исходящий | [docs/transformers/merchant-product-group.md](docs/transformers/merchant-product-group.md) |
| **MerchantPromocodeEventTransformer** | MerchantPromocode | Исходящий | [docs/transformers/merchant-promocode.md](docs/transformers/merchant-promocode.md) |
| **MerchantReviewEventTransformer** | MerchantReview | Двусторонний | [docs/transformers/merchant-review.md](docs/transformers/merchant-review.md) |
| **MerchantSaleEventTransformer** | MerchantSale | Исходящий | [docs/transformers/merchant-sale.md](docs/transformers/merchant-sale.md) |
| **ProductReviewEventTransformer** | ProductReview | Двусторонний | [docs/transformers/product-review.md](docs/transformers/product-review.md) |

**Двусторонний** — полноценный `toEvent()` + `fromEvent()` + хуки, используется для приёма и отправки.
**Исходящий** — `toEvent()` полноценный, `fromEvent()` реализован формально (заглушка), приём событий не предполагается.
