# RabbitMQ Integration — Индекс задач

## Обзор

Интеграция с RabbitMQ через пакет `multi_vendor_delivery_rabbitmq_package` (composer: `comrades/laravel-rabbitmq-events`).

**Общий объём:**
- 10 входящих сущностей (consume)
- 4 исходящих сущности (publish)
- 12 Transformers
- ~15 миграций
- ~50 тестов

---

## Граф зависимостей

```
01_setup
    │
    ├──────────────────────────────────────┐
    │                                      │
    ▼                                      ▼
02_incoming_shops                    07_outgoing_customers
    │                                      │
    ▼                                      │
03_incoming_categories                     │
    │                                      │
    ▼                                      │
04_incoming_products ◄─────────────────────┤
    │                                      │
    ├────────────┬─────────────┐           │
    │            │             │           │
    ▼            ▼             ▼           ▼
05_incoming  06_incoming   08_outgoing  09_outgoing
_marketing   _reviews      _orders      _reviews
```

---

## Список задач

### Этап 1: Базовая настройка
| # | Файл | Название | Приоритет | Зависимости |
|---|------|----------|-----------|-------------|
| 01 | [01_setup.md](./01_setup.md) | Базовая настройка | Критический | — |

### Этап 2: Входящие (критический путь)
| # | Файл | Название | Приоритет | Зависимости |
|---|------|----------|-----------|-------------|
| 02 | [02_incoming_shops.md](./02_incoming_shops.md) | Магазины (shops) | Критический | 01 |
| 03 | [03_incoming_categories.md](./03_incoming_categories.md) | Категории (categories) | Критический | 02 |
| 04 | [04_incoming_products.md](./04_incoming_products.md) | Товары (products) | Критический | 03 |

### Этап 3: Входящие (маркетинг)
| # | Файл | Название | Приоритет | Зависимости |
|---|------|----------|-----------|-------------|
| 05 | [05_incoming_marketing.md](./05_incoming_marketing.md) | Stories, Promotions, ProductGroups, Banners, Promocodes | Средний | 04 |

### Этап 4: Входящие (отзывы)
| # | Файл | Название | Приоритет | Зависимости |
|---|------|----------|-----------|-------------|
| 06 | [06_incoming_reviews.md](./06_incoming_reviews.md) | StoreReview, ProductReview | Низкий | 04 |

### Этап 5: Исходящие
| # | Файл | Название | Приоритет | Зависимости |
|---|------|----------|-----------|-------------|
| 07 | [07_outgoing_customers.md](./07_outgoing_customers.md) | Клиенты (customers) | Высокий | 01 |
| 08 | [08_outgoing_orders.md](./08_outgoing_orders.md) | Заказы (orders) | Критический | 07, 04 |
| 09 | [09_outgoing_reviews.md](./09_outgoing_reviews.md) | Отзывы (reviews) | Низкий | 01, 04 |

---

## Рекомендуемый порядок выполнения

```
Неделя 1:
├── 01_setup
├── 02_incoming_shops
└── 03_incoming_categories

Неделя 2:
├── 04_incoming_products
└── 07_outgoing_customers

Неделя 3:
├── 05_incoming_marketing (можно параллельно)
└── 08_outgoing_orders

Неделя 4:
├── 06_incoming_reviews
└── 09_outgoing_reviews
```

---

## Общие артефакты

### Файловая структура
```
app/
├── RabbitMQ/
│   ├── Transformers/
│   │   ├── BaseEventTransformer.php
│   │   ├── StoreEventTransformer.php
│   │   ├── CategoryEventTransformer.php
│   │   ├── ProductEventTransformer.php
│   │   ├── StoryEventTransformer.php
│   │   ├── PromotionEventTransformer.php
│   │   ├── ProductGroupEventTransformer.php
│   │   ├── BannerEventTransformer.php
│   │   ├── PromocodeEventTransformer.php
│   │   ├── StoreReviewEventTransformer.php
│   │   ├── ProductReviewEventTransformer.php
│   │   ├── CustomerEventTransformer.php
│   │   └── OrderEventTransformer.php
│   └── Conditions/
│       ├── CustomerHasPhoneCondition.php
│       └── ReviewHasUserCondition.php
config/
└── rabbitmq-events.php
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

### Supervisor (production)
```ini
[group:storefront-rabbitmq]
programs=storefront-outbox-customers,storefront-outbox-orders,storefront-outbox-store-reviews,storefront-outbox-product-reviews,storefront-consumer-shops,storefront-consumer-categories,storefront-consumer-products,storefront-consumer-stories,storefront-consumer-promotions,storefront-consumer-product-groups,storefront-consumer-banners,storefront-consumer-promocodes,storefront-consumer-store-reviews,storefront-consumer-product-reviews
```

---

## Метрики успеха

- [ ] Все 10 consumers работают
- [ ] Все 4 publishers работают
- [ ] Идемпотентность: повторная обработка не создаёт дубликатов
- [ ] Pending References: зависимости резолвятся автоматически
- [ ] Изображения: скачиваются и сохраняются локально
- [ ] Тесты: 100% покрытие happy path + edge cases
