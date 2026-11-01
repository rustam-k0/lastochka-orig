# ТЗ: Приём B2C-аналитики

## 1. Цель

Приём батчей поведенческих событий мобильного приложения (LASTOCHKI-JAMI-11659). Storefront события не хранит: каждое валидное событие уходит в шину (`event_outbox`, routing key `b2c.<event_type>`) и складывается сервисом аналитики.

## 2. База

| | |
|---|---|
| Endpoint | `POST /api/v1/analytics/events` |
| Auth | `[OPTIONAL AUTH]` — работает анонимно и с Bearer-токеном |
| Throttle | 120/мин |
| Заголовок | `X-Store-Code` — опционально, код магазина; попадает в `shop_code` события |

## 3. Endpoint

### 3.1 POST /api/v1/analytics/events [OPTIONAL AUTH]

Принимает батч событий. Валидация двухуровневая:

- **Форма батча** — жёсткая (422 при нарушении): `events` — required, array, min 1, max 100 (`config/analytics.php: max_batch_size`); каждый элемент — объект.
- **Каждое событие** — мягкая: невалидное событие не валит батч, оно попадает в `rejected` с причиной, остальные принимаются.

**Body:**
```json
{
  "events": [
    {
      "event_type": "product.viewed",
      "client_id": "6f0d5c9e-6a3e-4b8e-9f2a-111111111111",
      "session_id": "6f0d5c9e-6a3e-4b8e-9f2a-222222222222",
      "occurred_at": "2026-07-29T10:30:00+03:00",
      "product_id": 5
    }
  ]
}
```

Обязательные поля каждого события (проверяются per-event):

| Поле | Тип | Правило |
|---|---|---|
| `event_type` | string | Из whitelist `config/analytics.php: allowed_events`: `app.opened`, `product.viewed`, `category.viewed`, `cart.item_added`, `cart.item_removed`, `search.performed`, `stock.intent_denied`, `checkout.started` |
| `client_id` | string (UUID) | Устойчивый UUID устройства |
| `session_id` | string (UUID) | UUID сессии |
| `occurred_at` | string (ISO 8601) | Не старше 7 дней (`max_event_age_days`) и не дальше 5 минут в будущее (`max_future_skew_minutes`) |

Прочие поля события произвольны и передаются в шину как есть. Размер одного события в JSON — не более 8192 байт (`max_payload_bytes`).

**Поля `product.viewed`** (событие отправляется при закрытии карточки товара):

| Поле | Тип | Описание |
|---|---|---|
| `product_id` | int | id товара |
| `duration_ms` | int | сколько карточка была открыта, мс |
| `entry_point` | string | откуда открыта карточка: `home`, `catalog`, `search`, `product_group`, `cart`, `favorites`, `order`, `story`, `deeplink`, `barcode`, `other` |
| `product_group_id` | int, nullable | id товарной группы, обязателен при `entry_point = product_group` |

Для авторизованного запроса `product.viewed` дополнительно фиксируется в `product_views` (автогруппа «Смотрел, но не купил», `ProductViewRecorder`), если `duration_ms >= 3000` (`config/product_groups.php: personal_view_min_duration_ms`) и карточка открыта не из группы с `fill_type = viewed_not_bought`. Событие без `duration_ms` не фиксируется. Ошибка записи просмотра не влияет на приём события.

Точка входа передаётся полем `entry_point`, а не `source`: поле `source` в событии шины занято источником публикации (`storefront`) и перезаписывается.

**Поведение с client_id:** для авторизованного запроса сервер подставляет `client_id = user_id` из токена, исходный UUID устройства сохраняется в `device_client_id`; дополнительно публикуется событие склейки `b2c.identity.linked`. Для анонимного запроса `client_id` остаётся UUID-ом устройства.

**Response 202:**
```json
{
  "accepted": 2,
  "rejected": [
    { "index": 1, "reason": "unknown event_type" }
  ]
}
```

Возможные `reason`: `event must be an object`, `unknown event_type`, `client_id must be a UUID`, `session_id must be a UUID`, `occurred_at is required (ISO8601)`, `occurred_at is not a valid timestamp`, `occurred_at is too old`, `occurred_at is in the future`, `event payload is too large`.

**Ошибки:** 422 — нарушение формы батча (`events` отсутствует/пуст/больше 100 элементов), 429 — превышен throttle.

## 4. Routes

```
Route::post('v1/analytics/events', [AnalyticsEventController::class, 'store'])
    ->middleware([OptionalAuth::class, 'throttle:120,1']);
```
