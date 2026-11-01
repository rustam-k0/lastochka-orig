# Время готовности товара в API сборщика

Контракт изменённого GraphQL API курьерской системы (`/graphql-delivery`) для мобильного приложения сборщика. Описывает, что отдаёт бэкенд по товарам, которые готовы начиная с определённого времени (кулинария, выпечка).

## Что изменилось

1. В тип `Product` добавлены три поля: `ready_fixed_time`, `ready_at`, `is_ready`.
2. Поля доступны во всех запросах, которые уже отдают `Product`. Новых запросов и аргументов нет.
3. Мутации сборки не изменились: `ProductConfirm` подтверждает товар независимо от времени готовности.

## База

| | |
|---|---|
| Endpoint | `POST /graphql-delivery` |
| Dev | `https://djami.comrades.dev/graphql-delivery` |
| Prod | `https://admin.lastochki.store/graphql-delivery` |
| Протокол | GraphQL (HTTP всегда 200, ошибки в `errors[]`) |
| Content-Type | `application/json` |
| Auth | `Authorization: Bearer <Sanctum-токен>`, guard `api` |
| Доступ | Запросы, отдающие `Product`, закрыты правилом `onShift`: у пользователя должна быть открытая смена в магазине |
| Rate limit | нет |

## Типы

```graphql
type Product {
  id: Int
  articul: String
  title: String
  info_weight_type_id: Int
  info_weight: Int
  remain: Remain
  img: Img
  price: Price
  plu: String

  "новое; может быть null"
  ready_fixed_time: String
  "новое; может быть null"
  ready_at: DateTime
  "новое; null не возвращается"
  is_ready: Boolean
}
```

| Поле | Тип | Значение | Пример |
|---|---|---|---|
| `ready_fixed_time` | `String` | Время готовности товара в формате `HH:MM`. `null` означает, что товар готов всегда | `"14:30"` |
| `ready_at` | `DateTime` | Момент готовности на текущую дату сервера, формат `Y-m-d H:i:s`, часовой пояс Europe/Moscow, без offset. `null` означает, что время готовности не задано | `"2026-07-30 14:30:00"` |
| `is_ready` | `Boolean` | Готов ли товар на момент запроса. Считается по серверному времени, часы устройства не участвуют | `false` |

Формат `DateTime` здесь тот же, что у `Assembly.cooking_started_at` и `Order.slot_start_date`, то есть не ISO 8601.

## Где поля доступны

Поля объявлены на типе `Product`, поэтому приходят в любом запросе, который его отдаёт:

| Запрос | Путь до `Product` |
|---|---|
| `assembly_items(assembly_id: Int!)` | `order_item.product` |
| `assembly_items(assembly_id: Int!)` | `replacements.product` (товары на замену) |
| `assembly_item(id: Int!)` | `order_item.product` |
| `order(id: Int!)` | `items.product` |
| `orders_paginate`, `collector_orders_paginate` | `data.items.product` |
| `product(id: Int!)` | корень ответа |
| `product_search`, `product_global_search`, `product_search_by_shop` | корень ответа |

## Поведение сервера

Готовность ежедневная: `ready_at` резолвится на текущую дату сервера при каждом запросе. Товар с `ready_fixed_time = 14:30` отдаёт `ready_at = <сегодня> 14:30:00` и 30 июля, и 31 июля.

| Условие | `ready_fixed_time` | `ready_at` | `is_ready` |
|---|---|---|---|
| У товара не задано время готовности | `null` | `null` | `true` |
| Задано `14:30`, серверное время `09:00` | `"14:30"` | `"<сегодня> 14:30:00"` | `false` |
| Задано `14:30`, серверное время `15:00` | `"14:30"` | `"<сегодня> 14:30:00"` | `true` |
| Модуль «Время готовности товаров» выключен в админке | `null` | `null` | `true` |
| Ошибка чтения настроек модуля | `null` | `null` | `true` |

`ready_fixed_time` отдаётся весь день, в том числе после наступления времени готовности: это постоянная характеристика товара. Признак «рано» несёт только `is_ready`.

Значение флага модуля кешируется на стороне сервера до 5 минут: после переключения модуля в админке поля меняют значение в пределах этого интервала.

## Мутации сборки

`ProductConfirm` и остальные мутации сборки принимают те же аргументы и не проверяют время готовности: подтверждение товара с `is_ready = false` проходит успешно и возвращает `{ code: 200, message: "OK" }`. Ограничения на сборку раньше времени на сервере нет.

## Коды ответов

HTTP-статус GraphQL-ответа всегда `200`, кроме сетевых и инфраструктурных ошибок.

| Ситуация | HTTP | Тело |
|---|---|---|
| Успех | 200 | `{"data": {...}}` |
| Токен отсутствует или недействителен | 200 | `{"errors":[{"message":"Unauthenticated.","extensions":{"category":"authentication"}}]}` |
| Нет открытой смены (`onShift`) | 200 | `{"errors":[{"message":"This action is unauthorized.","extensions":{"category":"authorization"}}]}` |
| Запрошено несуществующее поле | 200 | `{"errors":[{"message":"Cannot query field \"...\" on type \"Product\".","extensions":{"category":"graphql"}}]}` |
| Товар/сборка не найдены | 200 | `{"data":{"product":null}}` |

## Пример

```bash
curl -X POST https://djami.comrades.dev/graphql-delivery \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "query($assembly_id: Int!) { assembly_items(assembly_id: $assembly_id) { id status_id order_item { quantity product { id title ready_fixed_time ready_at is_ready } } } }",
    "variables": {"assembly_id": 1234}
  }'
```

Ответ:

```json
{
  "data": {
    "assembly_items": [
      {
        "id": 55101,
        "status_id": 1,
        "order_item": {
          "quantity": 1,
          "product": {
            "id": 12417,
            "title": "Салат оливье без мяса ласточка",
            "ready_fixed_time": "14:30",
            "ready_at": "2026-07-30 14:30:00",
            "is_ready": false
          }
        }
      },
      {
        "id": 55102,
        "status_id": 1,
        "order_item": {
          "quantity": 2,
          "product": {
            "id": 9001,
            "title": "Молоко 1 л",
            "ready_fixed_time": null,
            "ready_at": null,
            "is_ready": true
          }
        }
      }
    ]
  }
}
```

## Обратная совместимость

Существующие поля, запросы, аргументы и мутации не изменились. Три поля опциональны: запрос без них возвращает прежний ответ, старые версии приложения продолжают работать без изменений.

## Тестовые данные на dev

Магазин `shop_id = 4`, товары с заданным временем готовности: `12417` (14:30), `12424` (16:30), `12509` (21:00). Модуль «Время готовности товаров» на dev включён.
