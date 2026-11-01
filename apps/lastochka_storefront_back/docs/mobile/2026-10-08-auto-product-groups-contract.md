# Автоматические товарные группы: контракт для клиентского приложения

Контракт изменений REST API витрины (`lastochka_storefront_back`). Новых ручек и экранов нет, меняются существующие.

## Что изменилось

1. У товарной группы новое поле `fillType`.
2. Ручки товарных групп учитывают токен пользователя (личные группы собираются под него). Токен нужно передавать во все ручки ниже.
3. Событие `product.viewed` в `POST /analytics/events` получило поля `duration_ms`, `entry_point`, `product_group_id`.

## База

| | |
|---|---|
| Base URL (dev) | `https://store.djami.comrades.dev/api/v1` |
| Авторизация | `Authorization: Bearer <token>`, если пользователь вошёл. Без токена ручки работают как раньше |
| Формат | REST, `application/json` |

## 1. Товарные группы

### `GET /product-groups`
### `GET /stores/{store}/product-groups`
### `GET /product-groups/{id}`

Параметры без изменений. Rate limit: без лимита.

**200**, в каждой группе новое поле:

```json
{
  "id": 12,
  "title": "Специально для вас",
  "fillType": "viewed_not_bought",
  "productsCount": 7,
  "products": [ /* ProductResource, без изменений */ ]
}
```

| `fillType` | Значение |
|---|---|
| `manual` | товары выбраны вручную |
| `purchase_history` | личная группа |
| `viewed_not_bought` | личная группа |
| `is_new` | общая группа |
| `is_hurry_up` | общая группа |

Личная группа может отсутствовать в списке (`GET /product-groups`, `GET /stores/{store}/product-groups`), если сервер решил её не отдавать.

### `GET /product-groups/{id}/products`
### `GET /stores/{store}/product-groups/slug/{slug}/products`

Параметры и формат ответа без изменений. Теперь принимают `Authorization: Bearer`.

**200** `{ "hasMore": false, "data": [], "total": 0 }` для личной группы, если товаров для пользователя нет.

## 2. Событие просмотра карточки

### `POST /analytics/events`

Ручка существующая. Rate limit: 120 запросов в минуту (`429` при превышении). Авторизация необязательная, при наличии передавать `Authorization: Bearer`.

Событие отправлять при закрытии карточки товара (уход с экрана, переход в другую карточку, сворачивание приложения).

**Body:**

```json
{
  "events": [
    {
      "event_type": "product.viewed",
      "client_id": "7b1c2f3e-0d4a-4c1e-9a55-1f2e3d4c5b6a",
      "session_id": "f0e1d2c3-b4a5-4968-8776-655443322110",
      "occurred_at": "2026-10-08T12:30:00+03:00",
      "product_id": 4512,
      "duration_ms": 8400,
      "entry_point": "product_group",
      "product_group_id": 12
    }
  ]
}
```

| Поле | Тип | Описание |
|---|---|---|
| `event_type` | string | `product.viewed` |
| `client_id` | uuid | идентификатор устройства, постоянный |
| `session_id` | uuid | идентификатор сессии приложения |
| `occurred_at` | ISO 8601 | момент открытия карточки, не старше 7 дней |
| `product_id` | int | id товара |
| `duration_ms` | int | сколько карточка была открыта, мс |
| `entry_point` | string | откуда открыта карточка: `home`, `catalog`, `search`, `product_group`, `cart`, `favorites`, `order`, `story`, `deeplink`, `barcode`, `other` |
| `product_group_id` | int, nullable | id группы, обязателен при `entry_point = product_group` |

`events`: от 1 до 100 событий в запросе, событие не больше 8 КБ.

**202**

```json
{ "accepted": 1, "rejected": [] }
```

`rejected`: `[{ "index": 0, "reason": "..." }]` для событий, не прошедших проверку.

**422** `{ "message": "...", "errors": { "events": ["..."] } }` если `events` отсутствует или пустой.
