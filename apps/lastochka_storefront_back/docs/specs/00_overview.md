# Техническое задание: Backend API E-Commerce

## О проекте

Backend для интернет-магазина. REST API для сайта и мобильного приложения (Flutter), интеграции с внешними системами через RabbitMQ/HTTP.

**Системы-участники:**
- **Storefront Backend** (этот проект) — REST API для сайта и мобильного клиента
- **RetailCRM** — CRM (через RetailBridge)
- **DeliverySystem** / **slots-api** — система доставки со слотами (HTTP)
- **Yandex Go** — внешняя доставка (webhook `/v1/webhooks/yandex-delivery`)
- **YooKassa** — эквайринг (webhook `/v1/webhooks/yookassa`)
- **АТОЛ Онлайн** — фискализация чеков (webhook `/v1/webhooks/atol`) *(новое)*
- **CDEK / Почта России** — доставка в ПВЗ (через slots-api: `/v1/pickup/*`, `/v1/delivery/*/tariffs`) *(новое)*
- **Firebase (FCM)** — push-уведомления (FcmTokenController + отправка через Firebase Messaging)
- **Bagisto** — web-магазин (параллельная система)
- **1С** — учётная система (товары, остатки, заказы)
- **Курьерская система (Админка)** — управление контентом, промокодами, модерация
- **RabbitMQ** — брокер сообщений между всеми системами

## Стек

- PHP 8.4 / Laravel 12
- MySQL
- Laravel Sanctum (API-аутентификация)
- RabbitMQ (обмен событиями)

## Структура ТЗ

### Основные модули (Фаза 1-2)

| # | Документ | Домен | Фаза |
|---|----------|-------|------|
| 01 | [auth_profile.md](./01_auth_profile.md) | Авторизация и профиль | P0 |
| 02 | [addresses.md](./02_addresses.md) | Адреса пользователей | P0 |
| 03 | [stores.md](./03_stores.md) | Магазины | P0 |
| 04 | [catalog.md](./04_catalog.md) | Категории и товары | P0 |
| 05 | [cart.md](./05_cart.md) | Корзина + дополнение заказа | P0 |
| 06 | [orders.md](./06_orders.md) | Заказы + резервация товаров | P0 |
| 07 | [delivery.md](./07_delivery.md) | Слоты доставки | P1 |
| 08 | [payments.md](./08_payments.md) | Платежи и карты | P1 |

### Контент и социальные (Фаза 2-3)

| # | Документ | Домен | Фаза |
|---|----------|-------|------|
| 11 | [stories.md](./11_stories.md) | Истории (Stories) | P1 |
| 12 | [promotions.md](./12_promotions.md) | Акции | P1 |
| 13 | [product_groups.md](./13_product_groups.md) | Товарные группы | P1 |
| 14 | [banners.md](./14_banners.md) | Баннеры мерчантов | P1 |
| 16 | [pages.md](./16_pages.md) | Составные страницы (**не реализовано**) | P1 |
| 09 | [favorites.md](./09_favorites.md) | Избранное | P2 |
| 15 | [reviews.md](./15_reviews.md) | Отзывы (мерчант + товар) | P2 |

### Прочие модули (не было в первых фазах ТЗ, реализованы)

| # | Документ | Домен |
|---|----------|-------|
| 19 | [19_bonuses.md](./19_bonuses.md) | Бонусная программа и карта лояльности (`/v1/bonuses`, `/v1/loyalty-card`) |
| 21 | [21_client_orders_marketplace.md](./21_client_orders_marketplace.md) | Клиентские заказы marketplace (`/v1/client-orders`) |
| 22 | [22_notifications.md](./22_notifications.md) | Уведомления (`/v1/notifications`, `/v1/users/me/notification-settings`) |
| 23 | [23_fcm_tokens.md](./23_fcm_tokens.md) | FCM-токены для push (`/v1/fcm-tokens`) |
| 24 | [24_preorders.md](./24_preorders.md) | Заявки «под заказ» (`/v1/preorders`) |
| 25 | [25_product_issue_reports.md](./25_product_issue_reports.md) | Жалобы на неточности товара |
| 26 | [26_estimates.md](./26_estimates.md) | Сметы B2B |
| 27 | [27_pickup.md](./27_pickup.md) | Самовывоз (`/v1/stores/{store}/pickup-slots`) |
| 28 | [28_search_suggestions_metro.md](./28_search_suggestions_metro.md) | Поисковые подсказки и категории в выдаче (`/v1/search/suggestions`) |
| 29 | [29_comparisons.md](./29_comparisons.md) | Сравнение товаров (`/v1/comparisons`) *(новое)* |
| 30 | [30_analytics.md](./30_analytics.md) | Приём B2C-аналитики (`/v1/analytics/events`) *(новое)* |

Также см. [api-payment.md](../api-payment.md) — эндпоинты платежей (оплата заказа, возврат, payment cards, фискальные чеки) и [api_search.md](../api_search.md) — поиск товаров.

### Интеграции (Фаза 4 — после готовности API)

| # | Документ | Домен | Фаза |
|---|----------|-------|------|
| 17 | [rabbitmq.md](./17_rabbitmq.md) | Интеграция RabbitMQ | P3 |

**Примечание:** Синхронизация с внешними системами (external_id, маппинг сущностей) будет реализована через отдельный пакет со встроенной логикой. Поля `external_id` не добавляются в основные таблицы.

## Общие соглашения

### Форматы данных

| Тип | Формат | Пример |
|-----|--------|--------|
| Денежные суммы | `decimal(10,2)` в рублях | `500.00`, `99.99` |
| DateTime | ISO 8601 | `2024-01-15T10:30:00.000Z` |
| ID | `int` (внутренние) | `1, 2, 3...` |
| Пагинация | page начинается с 1 | `?page=1&perPage=20` |

**Денежные суммы в БД:** `decimal(12,2)` по всей цепочке карточка → корзина → заказ (миграция «деньги с копейками», LASTOCHKI-JAMI-11200).

**В API:** числа с плавающей точкой (float/double), копейки сохраняются:
```json
{
  "price": 89.50,
  "priceOld": 99.00,
  "total": 650.75
}
```

В товарных и корзинных Resource (`ProductResource`, `CartItemResource`, `PromotionProductResource`) цена отдаётся как float с округлением до 2 знаков (`round((float) $price, 2)`).

**Исключение:** бонусные суммы — **целые рубли `int`**: `"balance": 1500` в `GET /v1/bonuses`, `bonusSpend`/`bonusEarn` в `GET /v1/client-orders`.

### Авторизация

- `Authorization: Bearer <token>`
- Токены — **Laravel Sanctum (opaque token, не JWT)**, expiration не истекает (`config/sanctum.php:expiration = null`).
- Четыре режима эндпоинтов:
  - **`[AUTH]`** — требуют Bearer (`auth:sanctum` middleware). Без токена → `401 Unauthorized`.
  - **`[OPTIONAL AUTH]`** — Bearer опционален (`OptionalAuth` middleware). С токеном эндпоинт учитывает пользователя (фильтрует по активному адресу, проставляет `isFavorite`, привязывает событие к пользователю и т.п.), без токена — отдаёт общий результат. Применяется к `GET /v1/product-groups`, `GET /v1/stores/{store}/product-groups`, `GET /v1/product-groups/{productGroup}`, `GET /v1/search/products`, `GET /v1/search/recommendations` *(новое)*, `POST /v1/analytics/events` *(новое)*.
  - Публичные (без пометки) — доступны без авторизации (каталог, stores, cities, brands, stories, promotions, banners, `GET /v1/estimates/shared/{token}`, `/v1/pickup/*`, `/v1/delivery/options`, `/v1/delivery/*/tariffs`, webhook'и). `GET /v1/search/suggestions` — тоже публичный, но резолвит Bearer через guard sanctum без middleware (детали в api_search.md).
  - **Server-to-server** — вебхуки (`/v1/webhooks/*` — без auth, валидация на стороне обработчика) и internal-ручки: `POST /v1/internal/orders/{order}/settle-payment` (middleware `internal.token`), `POST /v1/internal/yandex-claims/{claimId}/cancel`. Мобильным клиентом не используются. *(новое)*

### Ответы API

**Успех:**
```json
{
  "data": { ... }
}
```

**Список с пагинацией:**
```json
{
  "hasMore": true,
  "data": [...],
  "total": 100
}
```

**Ошибка:**
```json
{
  "message": "Validation failed",
  "errors": {
    "phone": ["Invalid phone format"]
  }
}
```

### HTTP коды

| Код | Использование |
|-----|---------------|
| 200 | Успешный запрос |
| 201 | Создан новый ресурс (`POST /orders`, `POST /reviews`, `POST /preorders`, `POST /products/{id}/report-issue`, `POST /addresses`, `POST /estimates`, `POST /estimates/{id}/items`, `POST /estimates/{id}/clone`, `POST /comparisons/items`) |
| 204 | Успешно, без тела ответа (`DELETE /cart`, `DELETE /payment-cards/{id}`, `DELETE /estimates/{id}`, `POST /auth/logout`, `DELETE /auth/account`, `DELETE /addresses/{id}`, `DELETE /fcm-tokens`, `DELETE /comparisons/*`, `DELETE /addresses/{address}/photos/{photo}`) |
| 401 | Не авторизован (нет/невалидный Bearer на `[AUTH]`-эндпоинте) |
| 403 | Доступ запрещён (policy не пропускает, фича-флаг отключён — `Модуль X отключён`) |
| 404 | Ресурс не найден (model binding или `abort(404)`) |
| 409 | Конфликт состояний (race condition при supplement — `OrderCannotBeSupplementedException`; смена телефона на уже занятый номер) |
| 422 | **Ошибка валидации FormRequest или бизнес-правила** (`Товар не найден`, `Промокод уже применён`, `У адреса нет координат`) |
| 500 | Внутренняя ошибка |

> **Внимание:** Laravel 12 `FormRequest` возвращает **422**, не 400. Код 400 в этом API не используется.

### Rate limits (throttle middleware)

| Эндпоинт | Лимит |
|---|---|
| `POST /v1/orders` | 5/мин на пользователя |
| `POST /v1/orders/{order}/cancel` | 10/мин |
| `POST /v1/orders/{order}/supplement` | 5/мин |
| `POST /v1/orders/{order}/pay` | 5/мин |
| `POST /v1/orders/{order}/refund` | 5/мин |
| `GET /v1/search/products`, `/search/stores/{store}/products`, `/search/suggestions`, `/search/recommendations` | 100/мин на IP |
| `GET /v1/bonuses`, `GET /v1/loyalty-card` | 30/мин |
| `POST /v1/products/{product}/preorders` | 5/мин |
| `POST /v1/products/{product}/report-issue` | 5/мин |
| `POST /v1/client-orders` (marketplace) | 5/мин |
| `DELETE /v1/client-orders/{id}` (marketplace) | 10/мин |
| `/v1/comparisons/*` (вся группа) *(новое)* | 60/мин |
| `POST /v1/analytics/events` *(новое)* | 120/мин |
| `POST /v1/addresses/{address}/photos` *(новое)* | 10/мин |
| `DELETE /v1/addresses/{address}/photos/{photo}` *(новое)* | 30/мин |
| `POST/PUT /v1/orders/{order}/review` *(новое)* | 10/мин |

### Marketplace-режим

Часть эндпоинтов условна и активируется только при включённом `marketplace_mode` (настройка в админке `projectModules`). При выключенном режиме:
- `GET /v1/cart` отдаёт `storeGroups` (стандартный shape), при включённом — `dateGroups` (группировка по датам доставки).
- `GET /v1/cart/delivery-summary` → **404** (middleware `marketplace.mode`).
- `GET|POST|DELETE /v1/client-orders` → **404**.
- Промокоды с `store_id = null` (platform-wide) применяются только в marketplace.

Детали архитектуры — см. `docs/design-analysis/marketplace-model.md`.

### Другие фича-флаги (projectModules) *(новое)*

| Флаг | Влияние на API |
|---|---|
| `address_objects` | При выключенном — `POST/DELETE /v1/addresses/{address}/photos` → **404** (middleware `address-objects-enabled`) |
| `heavy_orders` / `courier_assignment` | Поля `isHeavy`, `couriersCount`, `isJointDelivery`, `weightTotal` в корзине/заказе (см. 05, 06) |
| `assembly_cost` | Поле `assemblyCost` в корзине/заказе, включение стоимости сборки в total (см. 05, 06) |
| `collector_payment` | Синхронное списание оплаты сборщиком через internal-ручку (см. 08) |

Все флаги читаются через `ModuleConfigService` (настройки `projectModules` из админки).

## Архитектура взаимодействия

```
┌─────────────┐     ┌─────────────────┐     ┌──────────────┐
│   Flutter   │────▶│  Storefront API │────▶│    MySQL     │
│    App      │◀────│   (Laravel)     │◀────│              │
└─────────────┘     └────────┬────────┘     └──────────────┘
                             │
                             ▼
                    ┌────────────────┐
                    │   RabbitMQ     │
                    └───────┬────────┘
                            │
    ┌───────────────────────┼───────────────────────┐
    ▼                       ▼                       ▼
┌─────────────┐   ┌─────────────────┐   ┌─────────────────┐
│RetailBridge │   │    Bagisto      │   │ DeliverySystem  │
│ (→RetailCRM)│   │    (Web)        │   │    (Слоты)      │
└─────────────┘   └─────────────────┘   └─────────────────┘
    ▼                       ▼
┌─────────────┐   ┌─────────────────┐
│     1С      │   │ Курьерская      │
│  (Остатки,  │   │ система         │
│   Заказы)   │   │ (Контент,       │
└─────────────┘   │  Промокоды)     │
                  └─────────────────┘
```

### Потоки данных

| Источник | → Получатель | Данные |
|----------|--------------|--------|
| Bagisto → Storefront | Товары, категории, магазины |
| 1С → Storefront | Остатки товаров (stock) |
| Storefront → RetailCRM | Клиенты, заказы |
| Storefront → 1С | Заказы (резервация) |
| Курьерская → Storefront | Истории, акции, баннеры, промокоды, отзывы |
| DeliverySystem ← Storefront | Слоты доставки (HTTP API) |

## Приоритеты фаз

### Фаза 1: Базовое API (P0)
- Авторизация OTP
- Профиль пользователя
- Магазины и категории
- Товары (с КБЖУ)
- Корзина
- Создание заказа с резервацией товаров
- Адреса пользователей

### Фаза 2: Контент и расширения (P1)
- Истории (Stories)
- Акции
- Товарные группы
- Баннеры мерчантов
- Составные страницы (Home, Store Page, Catalog)
- Промокоды с датами действия
- Дополнение заказа
- DeliverySystem API (слоты)
- Эквайринг (оплата картой)

### Фаза 3: Социальные функции (P2)
- Избранное
- Отзывы на мерчантов
- Отзывы на товары
- Рейтинги (расчёт на основе отзывов)
- Push-уведомления

### Фаза 4: Интеграции RabbitMQ (P3)
- RabbitMQ producers (отправка событий)
- RabbitMQ consumers (приём событий)
- Синхронизация с CRM
- Синхронизация с 1С
- Обработка внешних обновлений (stock, статусы)
