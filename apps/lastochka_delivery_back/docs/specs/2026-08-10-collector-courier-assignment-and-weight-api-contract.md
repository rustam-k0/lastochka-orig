# Назначение курьера сборщиком и вес заказа: контракт API приложения сборщика (MID-214, MID-191)

Контракт изменённого GraphQL API `mendev_delivery_back` для мобильного приложения сборщика. Описывает, что отдаёт и принимает бэкенд.

## Что изменилось

1. В типе `User` появились поля `is_busy` (курьер занят) и `active_delivery_orders_count` (число заказов в работе). Доступны в выдаче `shift_couriers`.
2. В типе `Order` появились поля `couriers` (список назначенных курьеров) и `total_weight_unit` (единица измерения веса).
3. `Order.total_weight` и `Order.is_weight_partial` приходят на любом статусе заказа, включая «создан» и «на сборке». Описание поля в SDL приведено в соответствие с поведением.
4. `Order.is_heavy` приходит на любом статусе заказа.
5. `Order.couriers_count` у подзаказа зоны сборки отдаёт значение корневого заказа. Раньше на подзаказе всегда было `0`.
6. `DeliveryAssignCouriers` принимает `order_id` подзаказа, назначение выполняется на корневой заказ. Раньше такой вызов завершался внутренней ошибкой сервера.
7. `DeliveryAssignCouriers` отклоняет курьера без открытой смены в магазине заказа.

## База

| | |
|---|---|
| Endpoint | `POST /graphql-delivery` |
| Dev | `https://raz-dev.mendev.ru/graphql-delivery` |
| Протокол | GraphQL (Lighthouse), HTTP-статус всегда 200, ошибки в `errors[]` |
| Auth | `Authorization: Bearer <token>` (Sanctum; токен выдаёт мутация `UserLogin(phone, password)`) |
| Content-Type | `application/json` |
| Доступ | `shift_couriers` и `DeliveryAssignCouriers` доступны под ability `collectorOnShift`: роль `collector` + открытая смена. Списки заказов доступны под ability `onShift` |
| Пагинация | `first` по умолчанию 10, максимум 100 |
| Rate limit | нет |

Функциональность назначения включается модулями `courier_assignment` или `heavy_orders` (админка → Настройки → Модули). На dev оба включены. Состояние модулей доступно через query `courier_assignment_enabled` и `heavy_orders_enabled`: оба без аргументов и без авторизации.

## Ручки

### `shift_couriers`: курьеры на смене магазина сборщика (изменён тип выдачи)

```graphql
query {
  shift_couriers {
    id
    name
    surname
    phone
    is_busy                        # новое
    active_delivery_orders_count   # новое
  }
}
```

Аргументов нет. Возвращает `[User]`: курьеров с открытой сменой в магазине текущей смены сборщика.

Занятые курьеры остаются в выдаче: занятость передаётся признаком `is_busy`, из списка такие курьеры не исключаются. Пустой массив означает, что в магазине нет ни одного курьера с открытой сменой, независимо от их занятости. Запрос от пользователя без роли `collector` или без открытой смены отклоняется ошибкой авторизации, а не пустым списком.

### `DeliveryAssignCouriers`: назначение курьеров на заказ (изменено поведение)

```graphql
mutation DeliveryAssignCouriers($order_id: Int!, $courier_ids: [Int!]!) {
  DeliveryAssignCouriers(order_id: $order_id, courier_ids: $courier_ids) { code message }
}
```

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| `order_id` | `Int!` | да | ID заказа. Допустим ID подзаказа зоны сборки, назначение выполняется на корневой заказ |
| `courier_ids` | `[Int!]!` | да | ID курьеров. Минимум 1. Для нетяжёлого заказа принимается ровно один, второй отклоняется с ошибкой. Для тяжёлого (`is_heavy = true` при включённом `heavy_orders` или `courier_assignment`) ограничения по количеству нет. Дубли в массиве схлопываются |

Ответ: `{ "data": { "DeliveryAssignCouriers": { "code": 200, "message": "OK" } } }`.

Повторный вызов добавляет курьеров к уже назначенным: переданный список не заменяет состав, уже назначенные курьеры пропускаются без ошибки (вызов идемпотентен). Снятие назначения этой мутацией не выполняется. Актуальный состав назначенных отдаёт `Order.couriers`.

При включённом `courier_assignment` назначение не стартует рейс: заказ остаётся в статусе 3 (`ORDER_ASSEMBLED`), назначение курьера в статусе 1 (`DELIVERY_WAIT_TO_START`). Доставку начинает курьер мутацией `DeliveryStart`. При выключенном `courier_assignment` и включённом `heavy_orders` назначение стартует доставку сразу (статус заказа 4).

Каждому назначенному курьеру отправляется push с `type = order_assigned` и `order_id` корневого заказа.

### `orders_paginate` / `order` / `collector_orders_paginate`: заказы (изменён тип `Order`)

Сигнатуры не менялись. В типе `Order` доступны поля веса и назначения:

```graphql
query {
  collector_orders_paginate(status: 3, first: 20) {
    data {
      id
      label
      status_id
      total_weight        # приходит на любом статусе
      total_weight_unit   # новое
      is_weight_partial
      is_heavy            # приходит на любом статусе
      couriers_count
      couriers { id name surname phone }   # новое
    }
  }
}
```

Аргумент `status` у `collector_orders_paginate` остался скалярным `Int`, семантика прежняя: значение `3` возвращает заказы в статусах 3 (собран), 4 (в доставке) и 5 (доставлен). Заказ с назначенным курьером из выдачи `status: 3` не исчезает. Виртуальных статусов (в частности `8`, «Назначен курьер» из админского `admin_orders_paginate`) в `graphql-delivery` нет.

`collector_orders_paginate` возвращает заказы, у которых сборка принадлежит авторизованному сборщику; заказы без начатой сборки в неё не попадают. Список доступных к сборке заказов отдаёт `orders_paginate`.

## Типы

```graphql
type User {
  id: Int
  name: String
  surname: String
  patronymic: String            # может быть null
  phone: String
  is_busy: Boolean              # новое
  active_delivery_orders_count: Int  # новое
  # ...прежние поля без изменений
}

type Order {
  id: Int
  status_id: Int
  total_weight: Int             # может быть null
  total_weight_unit: String     # новое, всегда "g"
  is_weight_partial: Boolean
  is_heavy: Boolean
  couriers_count: Int
  couriers: [User]              # новое, может быть пустым массивом
  # ...прежние поля без изменений
}
```

## Поведение сервера

### Занятость курьера

| Условие | Что отдаёт сервер |
|---|---|
| У курьера есть назначение в статусе 1 (ожидает начала доставки), 2 (в пути) или 3 (прибыл на место) | `is_busy = true`, `active_delivery_orders_count` равен числу таких назначений |
| Все назначения курьера в статусе 4 (доставлено) или 5 (отменено), либо назначений нет | `is_busy = false`, `active_delivery_orders_count = 0` |
| Курьер назначен на два заказа одновременно | `is_busy = true`, `active_delivery_orders_count = 2` |

Заказ, назначенный сборщиком и ещё не начатый курьером, делает курьера занятым: назначение находится в статусе 1.

### Вес заказа

| Условие | Что отдаёт сервер |
|---|---|
| Заказ оформлен на витрине | `total_weight` заполнен с момента создания заказа: это плановый вес по составу заказа |
| Сборка завершена (`AssemblyFinish`) | `total_weight` перезаписан фактическим весом: учитывает взвешенные позиции, замены и отмены |
| Заказ создан мимо витрины (1С, админка) и вес не сохранён | `total_weight` рассчитывается на лету по составу заказа тем же алгоритмом, что и при сборке |
| Ни у одной позиции заказа нет веса в карточке товара, либо позиций нет | `total_weight = null` |
| Часть позиций без веса в карточке товара | `is_weight_partial = true`, `total_weight` содержит сумму по остальным позициям (занижен) |
| Позиция отменена | В `total_weight` не входит |

Правила вклада позиции в вес: взвешенная сборщиком позиция входит фактическим весом; весовой или объёмный товар без взвешивания входит номинальным весом порций (`количество × шаг`); штучный товар входит как `вес из карточки × количество`; штучный товар без веса в карточке в сумму не входит и включает `is_weight_partial`.

Единица измерения `total_weight`: граммы, `total_weight_unit` всегда `"g"`. Миллилитры и литры считаются 1:1 к граммам.

### Признак «Тяжёлый заказ»

| Условие | Что отдаёт сервер |
|---|---|
| Признак сохранён в заказе (проставила витрина при оформлении или сборка при завершении) | `is_heavy = true` |
| Вес заказа известен, порог магазина не превышен | `is_heavy = false` |
| Вес в заказе не сохранён, модуль `heavy_orders` включён | `is_heavy` выводится из расчётного веса и порога магазина |
| Вес в заказе не сохранён, модуль `heavy_orders` выключен | `is_heavy = false` |

Порог задаётся в карточке магазина (`heavy_order_threshold`, граммы), сравнение строгое (больше порога). Порог не задан: `is_heavy = false`.

### Курьеры на заказе

| Условие | Что отдаёт сервер |
|---|---|
| Курьер не назначен | `couriers_count = 0`, `couriers = []` |
| Назначен один курьер | `couriers_count = 1`, `couriers` содержит один элемент |
| Совместная доставка тяжёлого заказа | `couriers_count` = число курьеров, `couriers` содержит всех назначенных |
| Назначение отменено курьером | Курьер исключён и из `couriers`, и из `couriers_count` |
| Доставка завершена | Курьер остаётся в `couriers` и в `couriers_count`: значение историческое |
| Запрошен подзаказ зоны сборки | `couriers_count` и `couriers` отдают данные корневого заказа |

## Коды ответов

HTTP-статус всегда 200. Ошибки приходят в `errors[]`.

Ошибки валидации `DeliveryAssignCouriers` имеют `extensions.category = "validation"`, поле `errors[].message` содержит служебный текст `Validation failed for the field [DeliveryAssignCouriers].`, а предназначенный пользователю текст лежит в `extensions.validation.<поле>[]` по ключу `order_id` либо `courier_ids.<индекс>`. Индекс соответствует позиции курьера в переданном массиве `courier_ids`.

| Ситуация | Текст в `extensions.validation` | Ключ |
|---|---|---|
| Оба модуля (`courier_assignment`, `heavy_orders`) выключены | `Назначение курьеров отключено` | `order_id` |
| Заказа с таким ID не существует (или он удалён) | `Выбранное значение для order id некорректно.` | `order_id` |
| Заказ существует, но принадлежит другому магазину | `Заказ не найден` | `order_id` |
| Заказ оформлен на самовывоз | `Заказ оформлен на самовывоз и не назначается курьеру` | `order_id` |
| Заказ ещё не собран | `Заказ еще не готов к доставке` | `order_id` |
| Нетяжёлый заказ уже взят в доставку | `Заказ уже был взят в доставку` | `order_id` |
| Для нетяжёлого заказа передано больше одного курьера | `Несколько курьеров можно назначить только на тяжёлый заказ` | `order_id` |
| Онлайн-заказ не оплачен при включённом модуле `collector_payment` | `Заказ не оплачен: спишите оплату перед назначением курьера` | `order_id` |
| Переданный ID не принадлежит курьеру | `Пользователь не является курьером` | `courier_ids.<индекс>` |
| Курьер закрыл смену между загрузкой списка и назначением | `Курьер <Фамилия Имя> не на смене в этом магазине. Обновите список курьеров` | `courier_ids.<индекс>` |

Прочие ошибки приходят обычным текстом в `errors[].message`:

| Ситуация | `errors[].message` | `extensions.category` |
|---|---|---|
| Токен отсутствует, просрочен, у пользователя нет роли `collector` или открытой смены (`shift_couriers`, `DeliveryAssignCouriers`) | `This action is unauthorized.` | `authorization` |
| Токен отсутствует или просрочен в запросах под `@guard` (`orders_paginate`, `order`, `collector_orders_paginate`) | `Unauthenticated.` | `authentication` |

Гонка «курьер взял заказ в промежутке между загрузкой списка и назначением» на нетяжёлом заказе отдаёт `Заказ уже был взят в доставку`. Для тяжёлого заказа такая ситуация ошибкой не является, допускается несколько назначений.

## Пример

```bash
curl -s https://raz-dev.mendev.ru/graphql-delivery \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ shift_couriers { id name surname is_busy active_delivery_orders_count } }"}'
```

```json
{
  "data": {
    "shift_couriers": [
      { "id": 3, "name": "Иван", "surname": "Петров", "is_busy": false, "active_delivery_orders_count": 0 },
      { "id": 7, "name": "Сергей", "surname": "Кузнецов", "is_busy": true, "active_delivery_orders_count": 1 }
    ]
  }
}
```

```bash
curl -s https://raz-dev.mendev.ru/graphql-delivery \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{"query":"query($id:Int){ order(id:$id){ id label status_id total_weight total_weight_unit is_weight_partial is_heavy couriers_count couriers { id name surname phone } } }","variables":{"id":42}}'
```

```json
{
  "data": {
    "order": {
      "id": 42,
      "label": "128",
      "status_id": 3,
      "total_weight": 16400,
      "total_weight_unit": "g",
      "is_weight_partial": false,
      "is_heavy": true,
      "couriers_count": 2,
      "couriers": [
        { "id": 7, "name": "Сергей", "surname": "Кузнецов", "phone": "+79991110001" },
        { "id": 9, "name": "Артём", "surname": "Волков", "phone": "+79991110002" }
      ]
    }
  }
}
```

```bash
curl -s https://raz-dev.mendev.ru/graphql-delivery \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{"query":"mutation($o:Int!,$c:[Int!]!){ DeliveryAssignCouriers(order_id:$o, courier_ids:$c){ code message } }","variables":{"o":42,"c":[7]}}'
```

```json
{
  "errors": [
    {
      "message": "Validation failed for the field [DeliveryAssignCouriers].",
      "extensions": {
        "validation": {
          "courier_ids.0": ["Курьер Кузнецов Сергей не на смене в этом магазине. Обновите список курьеров"]
        },
        "category": "validation"
      }
    }
  ]
}
```

## Обратная совместимость

Сигнатуры существующих query и мутаций не менялись, аргументы не добавлялись и не удалялись. Все новые поля (`is_busy`, `active_delivery_orders_count`, `couriers`, `total_weight_unit`) опциональны, поэтому клиент, который их не запрашивает, работает как раньше.

Семантика `collector_orders_paginate(status: 3)` не менялась: заказ с назначенным курьером из выдачи не исчезает, ломающего изменения нет.

Изменения в значениях существующих полей: `total_weight`, `is_weight_partial` и `is_heavy` теперь могут прийти заполненными у заказа, у которого раньше на статусах 1 и 2 приходил `null` или `false`; `couriers_count` у подзаказа зоны сборки теперь отдаёт значение корневого заказа вместо `0`. Прежние значения этих полей у собранных заказов не изменились.

Вызов `DeliveryAssignCouriers` с `order_id` подзаказа раньше завершался ошибкой `Cannot take a sub-order for delivery` (категория `internal`), теперь выполняется на корневом заказе.
