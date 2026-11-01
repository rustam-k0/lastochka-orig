# ТЗ: Интеграция RabbitMQ через пакет multi_vendor_delivery_rabbitmq_package

## 1. Цель

Реализовать полноценный двусторонний обмен данными с внешними системами (RetailCRM через RetailBridge, Bagisto, DeliverySystem) через RabbitMQ, используя пакет `comrades/laravel-rabbitmq-events`.

**Измеримый результат:**
- Storefront автоматически получает и синхронизирует: магазины, категории, товары, истории, акции, товарные группы, баннеры, промокоды, отзывы
- Storefront автоматически отправляет: клиентов, адреса клиентов, заказы, отзывы
- Гарантия exactly-once delivery через Transactional Outbox Pattern
- Идемпотентность обработки событий

---

## 2. Контекст

### 2.1 Расположение в архитектуре

- **Слой:** Infrastructure (Services/RabbitMQ)
- **Пакет:** `comrades/laravel-rabbitmq-events` (уже установлен в vendor)
- **Роль системы:** Producer + Consumer

### 2.2 Внешние системы

| Система | Отправляет нам | Получает от нас |
|---------|---------------|-----------------|
| Bagisto (через RabbitMQ) | shops, categories, products, stories, promotions, product_groups, banners, promocodes | orders, customers, reviews |
| RetailCRM (через RetailBridge) | customer.*, order.status.* | customer.*, order.*, reviews |
| DeliverySystem | shop.* (расписание) | — |

### 2.3 Триггеры

**Outgoing (публикация):**
- Eloquent events: `created`, `updated`, `deleted` на моделях
- Application events: `OrderCreated`, `OrderCancelled`, `OrderPaid`

**Incoming (потребление):**
- RabbitMQ consumer: `php artisan rabbitmq:consume {entity}`

---

## 3. Данные

### 3.1 Сущности для ВХОДЯЩЕЙ синхронизации (consume only)

| Entity | Model | Routing Keys | Примечание |
|--------|-------|--------------|------------|
| `shops` | `Store` | `shop.created`, `shop.updated`, `shop.deleted` | + schedule |
| `categories` | `Category` | `category.created`, `category.updated`, `category.deleted` | Иерархия через parent_id |
| `products` | `Product` | `product.created`, `product.updated`, `product.deleted`, `product.stock.changed` | + images, attributes |
| `stories` | `Story` | `story.created`, `story.updated`, `story.deleted` | + images |
| `merchant_sales` | `Promotion` | `merchant_sale.created`, `merchant_sale.updated`, `merchant_sale.deleted` | + products pivot |
| `merchant_product_groups` | `ProductGroup` | `merchant_product_group.created`, `merchant_product_group.updated`, `merchant_product_group.deleted` | + products pivot |
| `merchant_banners` | `Banner` | `merchant_banner.created`, `merchant_banner.updated`, `merchant_banner.deleted` | |
| `merchant_promocodes` | `Promocode` | `merchant_promocode.created`, `merchant_promocode.updated`, `merchant_promocode.deleted` | |
| `merchant_reviews` | `StoreReview` | `merchant_review.created`, `merchant_review.updated`, `merchant_review.deleted` | Модерация извне |
| `product_reviews` | `ProductReview` | `product_review.created`, `product_review.updated`, `product_review.deleted` | Модерация извне |

### 3.2 Сущности для ИСХОДЯЩЕЙ синхронизации (publish only)

| Entity | Model | Events | Routing Keys |
|--------|-------|--------|--------------|
| `customers` | `User` | `created`, `updated` | `customer.created`, `customer.updated` |
| `orders` | `Order` | custom events | `order.created`, `order.paid`, `order.cancelled` |
| `store_reviews` | `StoreReview` | `created`, `updated` | `merchant_review.created`, `merchant_review.updated` |
| `product_reviews` | `ProductReview` | `created`, `updated` | `product_review.created`, `product_review.updated` |

### 3.3 Формат сообщений (согласно aladdin_exchange.md)

**Общие метаполя каждого события:**
```json
{
  "event_id": "uuid",
  "event_type": "created|updated|deleted|...",
  "event_timestamp": "2024-01-15T10:30:00Z",
  "event_source": "storefront",
  "id": "external_id",
  "source": "storefront|bagisto|retailcrm",
  "shop_code": "MSK_001"
}
```

---

## 4. Логика

### 4.1 INCOMING: Обработка магазинов (shops)

**Routing keys:** `shop.created`, `shop.updated`, `shop.deleted`

**Маппинг полей:**
```
Event → Model
─────────────────────────────────
id → external_id (хранить в metadata)
shop_code → code
title → title
active → is_active
latitude → latitude
longitude → longitude
slot_time → slot_duration_minutes
open_24_7 → is_24_7
schedule → StoreSchedule (отдельная таблица)
```

**Особенности:**
- `schedule` — JSON объект с днями недели, нужно распарсить и создать записи в `store_schedules`
- При `deleted` — soft delete

### 4.2 INCOMING: Обработка категорий (categories)

**Routing keys:** `category.created`, `category.updated`, `category.deleted`

**Маппинг полей:**
```
Event → Model
─────────────────────────────────
id → external_id
shop_code → store_id (через Store::where('code', shop_code))
source → source
parent_id + parent_source → parent_id (найти по metadata)
title → title
image_url → image_path (скачать через DownloadsExternalImages)
active → is_active
meta_* → meta_* (SEO поля)
```

**Особенности:**
- Иерархия: `parent_id` + `parent_source` — нужно найти родительскую категорию через `event_sync_metadata`
- Если родитель ещё не создан → использовать `EventPendingReference` (пакет поддерживает)

### 4.3 INCOMING: Обработка товаров (products)

**Routing keys:** `product.created`, `product.updated`, `product.deleted`, `product.stock.changed`

**Маппинг полей:**
```
Event → Model
─────────────────────────────────
id → external_id
shop_code → store_id
sku → sku
barcodes → barcodes (JSON)
title → title
price → price (decimal 10,2)
discount_price → price_old (ИНВЕРСИЯ! discount_price это новая цена)
image_url → ProductImage (основное изображение)
quantity → quantity
unit_type → unit_type (enum: kg, piece)
description → description
meta_* → meta_* (SEO)
manufacturer → manufacturer
country → country
brand → brand
weight → weight_grams
nds → vat_rate
active → is_active
category_id + category_source → category_id (через metadata)
attributes → ProductAttribute (код-значение)
is_culinary → is_culinary
```

**Custom event `product.stock.changed`:**
```php
public function processStockChangedEvent(Model $product, array $eventData): void
{
    $product->update(['quantity' => $eventData['quantity']]);
}
```

**Особенности:**
- Изображение: если `image_url` указан — скачать и сохранить локально (trait `DownloadsExternalImages`)
- Attributes: массив `[{code, name, value}]` → создать/обновить `ProductAttribute`
- КБЖУ: извлечь из attributes по code (`calories`, `proteins`, `fats`, `carbs`)

### 4.4 INCOMING: Обработка историй (stories)

**Routing keys:** `story.created`, `story.updated`, `story.deleted`

**Маппинг полей:**
```
Event → Model
─────────────────────────────────
id → external_id
source → source
title → title
expires_at → expires_at
link_type → link_type (enum: none, product, store, external)
product_id + product_source → link_product_id (через metadata)
shop_id + shop_source → link_store_id (через metadata)
external_url → link_external_url
is_active → is_active
thumbnail_image_url → thumbnail_path (скачать)
images → StoryImage[] (скачать каждое)
```

### 4.5 INCOMING: Обработка акций (merchant_sales → Promotion)

**Routing keys:** `merchant_sale.created`, `merchant_sale.updated`, `merchant_sale.deleted`

**Маппинг полей:**
```
Event → Model
─────────────────────────────────
id → external_id
source → source
shop_id + shop_source → store_id
title → title
description → description
image_url → image_path (скачать)
discount_percent → discount_percent
starts_at → starts_at
ends_at → ends_at
is_active → is_active
products → PromotionProduct pivot (через metadata для product_id)
```

**Особенности:**
- `products` — массив `[{product_id, product_source}]`
- Для каждого товара: найти локальный ID через `event_sync_metadata`, создать pivot запись

### 4.6 INCOMING: Обработка товарных групп (merchant_product_groups → ProductGroup)

**Routing keys:** `merchant_product_group.created`, `merchant_product_group.updated`, `merchant_product_group.deleted`

**Маппинг полей:**
```
Event → Model
─────────────────────────────────
id → external_id
source → source
shop_id + shop_source → store_id
title → title
image_url → image_path
show_on_main → show_on_home
show_on_merchant_page → show_on_store_page
show_in_merchant_catalog → show_in_catalog
is_active → is_active
products → ProductGroupItem pivot
```

### 4.7 INCOMING: Обработка баннеров (merchant_banners → Banner)

**Routing keys:** `merchant_banner.created`, `merchant_banner.updated`, `merchant_banner.deleted`

**Маппинг полей:**
```
Event → Model
─────────────────────────────────
id → external_id
source → source
shop_id + shop_source → store_id
title → title
image_url → image_path (скачать)
link_type → link_type (enum: none, product, category)
product_id + product_source → link_product_id
category_id + category_source → link_category_id
is_active → is_active
```

### 4.8 INCOMING: Обработка промокодов (merchant_promocodes → Promocode)

**Routing keys:** `merchant_promocode.created`, `merchant_promocode.updated`, `merchant_promocode.deleted`

**Маппинг полей:**
```
Event → Model
─────────────────────────────────
id → external_id
source → source
shop_id + shop_source → store_id
title → title
code → code
discount_percent → value (+ type = 'percent')
valid_from → starts_at
valid_to → ends_at
is_active → is_active
```

### 4.9 INCOMING: Обработка отзывов (merchant_review, product_review)

**Routing keys:** `merchant_review.*`, `product_review.*`

**Маппинг полей (merchant_review → StoreReview):**
```
Event → Model
─────────────────────────────────
id → external_id
source → source
shop_id + shop_source → store_id
rating → rating
description → description
is_published → is_published
author_name → author_name
author_phone → author_phone
```

**Особенности:**
- Модерация извне: `is_published` приходит из админки
- При `is_published = true` → пересчитать рейтинг магазина/товара

---

### 4.10 OUTGOING: Публикация клиентов (customers)

**Model:** `User`
**Events:** `created`, `updated`

**Payload:**
```json
{
  "id": "1",
  "shop_code": "STOREFRONT",
  "source": "storefront",
  "phone": "+79991234567",
  "name": "Иван",
  "surname": "Иванов",
  "patronymic": null,
  "email": "ivan@example.com",
  "birthday": "1990-01-15",
  "gender": "male",
  "created_at": "2024-01-15T10:30:00Z",
  "updated_at": "2024-01-15T10:30:00Z"
}
```

### 4.11 OUTGOING: Публикация заказов (order.*)

**Model:** `Order`
**Custom Events:** `order.created`, `order.paid`, `order.cancelled`

**Важно:** Заказы публикуются через `CustomEventPublisher`, а не через Observer, потому что:
1. Заказ содержит snapshot данных (address, items, promocode)
2. Нужен полный payload с вложенными объектами
3. Событие `order.paid` триггерится отдельно от Eloquent

**Payload order.created:**
```json
{
  "id": "123",
  "shop_code": "MSK_001",
  "source": "storefront",
  "client_id": "1",
  "client_source": "storefront",
  "client_info": {
    "name": "Иван",
    "surname": "Иванов",
    "phone": "+79991234567",
    "email": "ivan@example.com"
  },
  "items": [
    {
      "id": "1",
      "source": "storefront",
      "product_id": "ext_123",
      "product_source": "bagisto",
      "product_name": "Молоко 3.2%",
      "unit_price": 89.00,
      "unit_price_effective": 76.00,
      "line_subtotal": 178.00,
      "line_discount_total": 26.00,
      "line_total": 152.00,
      "quantity": 2
    }
  ],
  "status": "created",
  "items_subtotal": 500.00,
  "discount_total": 50.00,
  "items_total_after_discount": 450.00,
  "shipping_total": 200.00,
  "grand_total": 650.00,
  "is_paid": false,
  "payment_type": "card",
  "slot_start_date": "2024-01-15T10:00:00Z",
  "slot_end_date": "2024-01-15T12:00:00Z",
  "shipping_method": "courier",
  "address": {
    "address": "Москва, ул. Ленина, д. 10",
    "entrance": "2",
    "intercom": "42",
    "floor": "5",
    "flat": "42",
    "latitude": 55.7558,
    "longitude": 37.6173
  },
  "created_at": "2024-01-15T10:30:00Z"
}
```

**Payload order.paid:**
```json
{
  "id": "123",
  "shop_code": "MSK_001",
  "source": "storefront",
  "is_paid": true,
  "payment_type": "card",
  "updated_at": "2024-01-15T10:31:00Z"
}
```

**Payload order.cancelled:**
```json
{
  "id": "123",
  "shop_code": "MSK_001",
  "source": "storefront",
  "status": "cancelled",
  "updated_at": "2024-01-15T12:00:00Z"
}
```

### 4.12 INCOMING: Обработка статуса заказа (order.status.changed)

**Routing key:** `order.status.changed`

**Логика:**
1. Найти заказ по `external_id` через metadata ИЛИ по `id` если `source = storefront`
2. Маппинг статуса:
   ```
   created → pending
   assembling → assembling
   assembled → assembled
   in_delivery → inDelivery
   delivered → delivered
   cancelled → cancelled
   returned → cancelled (с пометкой returned)
   ```
3. Обновить `tracking_number` если передан
4. Триггерить внутренние события (push-уведомления, etc.)

---

## 5. Интеграции

### 5.1 Пакет multi_vendor_delivery_rabbitmq_package (composer: comrades/laravel-rabbitmq-events)

**Уже предоставляет:**
- Transactional Outbox Pattern (таблица `event_outbox`)
- Idempotency через `event_sync_metadata`
- Pending References для отложенных связей (`event_pending_references`)
- Observer для автоматической публикации
- Consumer для обработки входящих событий
- Graceful shutdown, memory limit, batch processing

**Нужно реализовать:**
- Конфигурация `config/rabbitmq-events.php`
- Transformers для каждой сущности
- Conditions для условной публикации

### 5.2 Зависимости между сущностями

```
shop (независимая)
  ↓
category (зависит от shop, parent_category)
  ↓
product (зависит от shop, category)
  ↓
promotion, product_group, banner (зависят от shop, products)
  ↓
promocode (зависит от shop)
  ↓
story (может зависеть от product, shop)
  ↓
reviews (зависят от shop, product)
```

**Порядок запуска consumers:**
1. `shops` — первым
2. `categories` — вторым
3. `products` — третьим
4. Остальные — параллельно

### 5.3 Обработка отсутствующих зависимостей

Пакет поддерживает `EventPendingReference` для случаев, когда родительская сущность ещё не создана.

**Пример:** Приходит `product.created` с `category_id=123, category_source=bagisto`, но категория ещё не синхронизирована.

**Решение:**
```php
// В ProductEventTransformer — через трейт ResolvesExternalReferences
if (!$categoryMetadata) {
    $this->createPendingReference(
        entity: $product,
        fieldName: 'category_id',
        referenceType: 'categories',
        source: $eventData['category_source'],
        externalId: $eventData['category_id'],
        shopCode: $eventData['shop_code'],
        metadata: ['event_id' => $eventData['event_id']],
    );
}
```

### 5.4 Скачивание изображений

Storefront использует свой `downloadImage()` из `BaseEventTransformer`, а **НЕ** пакетный трейт `DownloadsExternalImages`:

```php
// Storefront использует свой downloadImage() из BaseEventTransformer,
// а НЕ пакетный трейт DownloadsExternalImages

class ProductEventTransformer extends BaseEventTransformer
{
    public function afterCreate(Model $product, array $eventData): void
    {
        if ($imageUrl = $eventData['image_url'] ?? null) {
            $localPath = $this->downloadImage($imageUrl, 'products');
            $product->images()->create(['path' => $localPath, 'is_primary' => true]);
        }
    }
}
```

---

## 6. Нефункциональные требования

### 6.1 Транзакционность

- Все операции с моделями + metadata в одной транзакции
- Пакет использует `DB::transaction()` с 3 попытками при deadlock

### 6.2 Идемпотентность

- Дедупликация по `event_id` (пакет делает автоматически)
- Timestamp-based ordering (пакет проверяет `last_event_timestamp`)

### 6.3 Производительность

- Batch size: 1000 событий
- Consumer prefetch: 1 сообщение
- Memory limit: 128MB (автоперезапуск)
- Retention: 7 дней для outbox

### 6.4 Мониторинг

Метрики для отслеживания:
- `event_outbox` — количество непубликованных событий
- `event_sync_metadata` — количество синхронизированных сущностей
- `event_pending_references` — количество неразрешённых ссылок

---

## 7. Ожидаемый результат

### 7.1 Конфигурация

```
config/
└── rabbitmq-events.php       # Полная конфигурация всех entities
```

### 7.2 Transformers

```
app/
└── RabbitMQ/
    └── Transformers/
        ├── StoreEventTransformer.php
        ├── CategoryEventTransformer.php
        ├── ProductEventTransformer.php
        ├── StoryEventTransformer.php
        ├── PromotionEventTransformer.php
        ├── ProductGroupEventTransformer.php
        ├── BannerEventTransformer.php
        ├── PromocodeEventTransformer.php
        ├── StoreReviewEventTransformer.php
        ├── ProductReviewEventTransformer.php
        ├── CustomerEventTransformer.php
        └── OrderEventTransformer.php
```

### 7.3 Conditions (опционально)

```
app/
└── RabbitMQ/
    └── Conditions/
        └── CustomerHasPhoneCondition.php   # Публиковать только с телефоном
```

### 7.4 Интеграция с Actions

```php
// В CreateOrderAction::execute()
public function execute(CreateOrderData $data): int
{
    return DB::transaction(function () use ($data) {
        // ... создание заказа ...

        // Публикация через CustomEventPublisher
        CustomEventPublisher::dispatch('orders', $order, 'created', [
            'client_id' => $order->user_id,
            'client_source' => 'storefront',
            'client_info' => [...],
            'items' => [...],
            // полный payload
        ], shopCode: $order->store->code);

        return $order->id;
    });
}
```

### 7.5 Миграции

```
database/migrations/
├── xxxx_add_external_id_to_stores_table.php
├── xxxx_add_external_id_to_categories_table.php
├── xxxx_add_external_id_to_products_table.php
├── xxxx_add_source_to_stories_table.php
├── xxxx_add_source_to_promotions_table.php
├── xxxx_add_source_to_product_groups_table.php
├── xxxx_add_source_to_banners_table.php
├── xxxx_add_source_to_promocodes_table.php
├── xxxx_add_source_to_store_reviews_table.php
├── xxxx_add_source_to_product_reviews_table.php
├── xxxx_add_external_id_to_users_table.php
└── xxxx_add_external_id_to_orders_table.php
```

### 7.6 Тесты

```
tests/
└── Feature/
    └── RabbitMQ/
        ├── StoreEventHandlerTest.php
        ├── CategoryEventHandlerTest.php
        ├── ProductEventHandlerTest.php
        ├── StoryEventHandlerTest.php
        ├── PromotionEventHandlerTest.php
        ├── ProductGroupEventHandlerTest.php
        ├── BannerEventHandlerTest.php
        ├── PromocodeEventHandlerTest.php
        ├── ReviewEventHandlerTest.php
        ├── CustomerPublisherTest.php
        ├── OrderPublisherTest.php
        ├── ReviewPublisherTest.php
        └── IdempotencyTest.php
```

### 7.7 Supervisor Configuration

```ini
# /etc/supervisor/conf.d/storefront-rabbitmq.conf

[program:storefront-outbox-customers]
command=php artisan rabbitmq:publish-outbox customers
autostart=true
autorestart=true
user=www-data
numprocs=1

[program:storefront-outbox-orders]
command=php artisan rabbitmq:publish-outbox orders
autostart=true
autorestart=true
user=www-data
numprocs=1

[program:storefront-outbox-store-reviews]
command=php artisan rabbitmq:publish-outbox store_reviews
autostart=true
autorestart=true
user=www-data
numprocs=1

[program:storefront-outbox-product-reviews]
command=php artisan rabbitmq:publish-outbox product_reviews
autostart=true
autorestart=true
user=www-data
numprocs=1

[program:storefront-consumer-shops]
command=php artisan rabbitmq:consume shops
autostart=true
autorestart=true
user=www-data
numprocs=1

[program:storefront-consumer-categories]
command=php artisan rabbitmq:consume categories
autostart=true
autorestart=true
user=www-data
numprocs=1

[program:storefront-consumer-products]
command=php artisan rabbitmq:consume products
autostart=true
autorestart=true
user=www-data
numprocs=2

# ... остальные consumers
```

---

## 8. Порядок реализации (рекомендуемый)

### Этап 1: Базовая настройка
1. Создать `config/rabbitmq-events.php`
2. Добавить переменные в `.env.example`
3. Запустить миграции пакета: `php artisan migrate`
4. Настроить exchanges: `php artisan rabbitmq:setup-exchanges`

### Этап 2: Входящие события (критический путь)
1. `shops` — StoreEventTransformer
2. `categories` — CategoryEventTransformer (с pending references)
3. `products` — ProductEventTransformer (с images, attributes)
4. Тесты для каждого

### Этап 3: Входящие события (маркетинг)
1. `stories` — StoryEventTransformer
2. `merchant_sales` — PromotionEventTransformer
3. `merchant_product_groups` — ProductGroupEventTransformer
4. `merchant_banners` — BannerEventTransformer
5. `merchant_promocodes` — PromocodeEventTransformer
6. Тесты

### Этап 4: Исходящие события
1. `customers` — CustomerEventTransformer
2. `orders` — OrderEventTransformer (через CustomEventPublisher)
3. Интеграция с существующими Actions
4. Тесты

### Этап 5: Отзывы (двусторонние)
1. `merchant_reviews`, `product_reviews` — входящие
2. `store_reviews`, `product_reviews` — исходящие
3. Тесты

### Этап 6: Production readiness
1. Supervisor configs
2. Мониторинг (Prometheus/Grafana)
3. Алерты
4. Документация по деплою

---

## 9. Риски и митигация

| Риск | Вероятность | Митигация |
|------|-------------|-----------|
| Порядок событий нарушен | Средняя | Pending References для зависимостей |
| Дубликаты событий | Низкая | Idempotency через event_id |
| RabbitMQ недоступен | Низкая | Transactional Outbox — события не теряются |
| Deadlock при высокой нагрузке | Низкая | 3 retry attempts в транзакции |
| Изображения недоступны | Средняя | Retry с exponential backoff, fallback к placeholder |
| Несоответствие форматов данных | Средняя | Строгая валидация в Transformer::validate() |

---

*Документ создан: 2025-01-29*
*Версия: 1.0*
