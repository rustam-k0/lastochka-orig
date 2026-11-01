# Отмена заказа сборщиком — OrderCancelByCollector

Контракт нового API для мобильного приложения сборщика (delivery_back, GraphQL). Ранее полная отмена заказа во время сборки была доступна только администратору из веб-панели (`OrderCancel` в purchase_back) и курьеру во время доставки (`OrderCancelByCourier`) — у сборщика такой ручки не было.

## Что предоставляется

1. Новая мутация `OrderCancelByCollector` — отменяет заказ целиком одним вызовом (в отличие от существующей `ProductCancel`, которая требует отмены каждого товара по отдельности и лишь как побочный эффект отменяет заказ, когда отменены все позиции).
2. Новый справочник причин `order_cancel_reasons` (query) — те же причины, что видит администратор при отмене заказа в админке.

## База

| | |
|---|---|
| Endpoint | `POST /graphql-delivery` |
| Dev | `https://raz-dev.mendev.ru/graphql-delivery` |
| Протокол | GraphQL (HTTP-статус всегда `200`, ошибки — в `errors[]`) |
| Content-Type | `application/json` |
| Auth | `Authorization: Bearer <sanctum_token>` (тот же токен, что уже используется для `AssemblyCancel`/`ProductCancel`) |
| Доступ | роль `collector` **и** открытая смена (`shifts` без `finished_at`) — то же условие, что уже действует на `AssemblyCancel`/`ProductCancel` |
| Rate limit | не задан |

## Ручки

### `OrderCancelByCollector` — отменить заказ целиком

```graphql
mutation OrderCancelByCollector($assembly_id: Int!, $reason_id: Int, $custom_reason: String) {
  OrderCancelByCollector(
    assembly_id: $assembly_id
    reason_id: $reason_id
    custom_reason: $custom_reason
  ) {
    code
    message
  }
}
```

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| `assembly_id` | `Int` | да | ID сборки (`Assembly.id`), которую собирает текущий пользователь. Сборка должна принадлежать вызывающему сборщику и быть незавершённой (`finished_at IS NULL`) — сборка другого сборщика или уже завершённая возвращает ошибку валидации. |
| `reason_id` | `Int` | нет* | ID причины из справочника `order_cancel_reasons`. Должен ссылаться на причину с `is_active = true`. |
| `custom_reason` | `String` | нет* | Свободный текст причины, до 1000 символов. |

\* Обязательна ровно одна из пар: сервер требует `reason_id` **или** непустой (после `trim`) `custom_reason`. Если оба пусты — ошибка валидации на `reason_id`. Если передано и то, и другое — используется `custom_reason`.

Ответ — тип `DefaultResponse`:
```json
{ "code": 200, "message": "OK" }
```

### `order_cancel_reasons` — справочник причин отмены

```graphql
query {
  order_cancel_reasons {
    id
    title
  }
}
```

Возвращает список активных причин (`is_active = true`), отсортированный не гарантирован (сервер не применяет `ORDER BY`). Авторизация на этот запрос не требуется — как и у соседних справочников `product_cancel_reasons`/`product_replace_reasons`, директив `@guard`/`@can` на нём нет.

## Типы

```graphql
type OrderCancelReason {
  id: Int
  title: String
}

type DefaultResponse {
  code: Int
  message: String
}
```

Оба поля `OrderCancelReason` не помечены как `NonNull` — `null` теоретически возможен, но в норме отсутствует (сервер всегда пишет `title` при создании причины).

## Поведение сервера

| Условие | Что делает/отдаёт сервер |
|---|---|
| Заказ найден, статус заказа = «на сборке» (`ORDER_PREPARING`) | Отменяет заказ: все позиции заказа переводятся в статус «отменена», статус заказа — в «отменён», в `operator_comment` заказа пишется текст причины (заголовок из `order_cancel_reasons` либо `custom_reason`). Сборка помечается завершённой. Возвращает `{code: 200, message: "OK"}`. |
| Заказ — под-заказ (сборка по зонам, `parent_order_id` не пуст) | Отменяется только этот под-заказ. Родительский заказ переводится в «отменён» автоматически, только если ВСЕ его под-заказы уже в терминальном статусе (отменены или собраны). Соседние под-заказы, которые ещё собираются в других зонах, не затрагиваются и не отменяются. |
| Статус заказа НЕ «на сборке» (уже собран/в доставке/доставлен/отменён) | Ошибка `user` (см. «Коды ответов»), заказ не меняется. |
| `assembly_id` не найден, принадлежит другому сборщику или уже завершён | Ошибка валидации на `assembly_id` (см. «Коды ответов»), заказ не меняется. |
| И `reason_id`, и `custom_reason` пусты | Ошибка валидации: `reason_id` обязателен. |
| Успешная отмена | Сервер также отправляет best-effort HTTP-уведомление в purchase_back (`/purchase/order/cancel-by-collector`) — на момент публикации этого контракта эндпоинт на стороне purchase_back не реализован, ответ клиента (`code`/`message`) от результата этого уведомления не зависит. |

## Коды ответов

Проверено живыми запросами на dev (`raz-dev.mendev.ru`) 2026-07-06.

| Ситуация | HTTP | `errors[].extensions.category` | Детали |
|---|---|---|---|
| Успех | 200 | — | `data.OrderCancelByCollector = {code: 200, message: "OK"}` |
| `assembly_id` не передан вовсе (аргумент `Int!` схемы) | 200 | `graphql` | Запрос отклоняется на уровне GraphQL до валидатора: `errors[].message = "Field \"OrderCancelByCollector\" argument \"assembly_id\" of type \"Int!\" is required but not provided."` |
| `assembly_id` не найден, принадлежит другому сборщику или уже завершён (в т.ч. без токена — `Auth::id()` тогда `null`, ни одна сборка не принадлежит `null`) | 200 | `validation` | `errors[].extensions.validation.assembly_id = ["Сборка не найдена"]` |
| Валидный токен, сборка своя, но роль ≠ `collector` или нет открытой смены | 200 | `authorization` | `errors[].message = "This action is unauthorized."` |
| `reason_id` не активен / не существует | 200 | `validation` | `errors[].extensions.validation.reason_id = ["Выбранное значение для reason id некорректно."]` |
| `custom_reason` длиннее 1000 символов | 200 | `validation` | `errors[].extensions.validation.custom_reason = ["Количество символов в поле custom reason не может превышать 1000."]` |
| Ни `reason_id`, ни `custom_reason` не переданы | 200 | `validation` | `errors[].extensions.validation.reason_id = ["Поле reason id обязательно для заполнения."]` |
| Заказ не в статусе «на сборке» | 200 | `user` | `errors[].message = "Заказ не находится на сборке"` |

Валидационные сообщения локализованы на русский (Laravel `lang/ru`) — примеры выше взяты из реального ответа сервера, а не переведены вручную.

## Пример

Запрос:
```bash
curl -X POST https://raz-dev.mendev.ru/graphql-delivery \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "query": "mutation($assembly_id: Int!, $reason_id: Int, $custom_reason: String) { OrderCancelByCollector(assembly_id: $assembly_id, reason_id: $reason_id, custom_reason: $custom_reason) { code message } }",
    "variables": { "assembly_id": 123, "reason_id": 4, "custom_reason": null }
  }'
```

Ответ (успех):
```json
{
  "data": {
    "OrderCancelByCollector": { "code": 200, "message": "OK" }
  }
}
```

Ответ (заказ уже собран):
```json
{
  "data": { "OrderCancelByCollector": null },
  "errors": [
    {
      "message": "Заказ не находится на сборке",
      "extensions": { "category": "user" }
    }
  ]
}
```

## Обратная совместимость

Полностью новые ручки — существующие запросы `AssemblyCancel`, `ProductCancel`, схема `Order`/`Assembly` не изменены. Мобильному приложению курьера (`OrderCancelByCourier`) ничего не поменялось: рефакторинг серверной части (общий метод отмены сборки, ранее приватный в `ProductCancel`) — внутренняя реализация, поведение и ответ `ProductCancel` для клиента идентичны прежним.
