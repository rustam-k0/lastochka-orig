# Переключение корзины на магазин (MID-204)

Контракт нового API витрины для мобильного приложения и веб-фронта. Описывает, что принимает и отдаёт бэкенд после смены адреса доставки или магазина самовывоза.

## Что предоставляется

1. Новая ручка `POST /api/v1/cart/store`: перепроверяет позиции корзины по ассортименту целевого магазина, перепривязывает доступные позиции к нему, удаляет недоступные и возвращает корзину вместе со списком удалённых товаров.
2. Ответ ручки повторяет ответ `GET /api/v1/cart` (та же форма, без обёртки `data`) и дополнен тремя полями: `storeId`, `removedItems`, `hasUnavailableItems`.
3. Остальные ручки корзины не менялись.

## База

| | |
|---|---|
| Endpoint | `POST /api/v1/cart/store` |
| Dev | `https://store.raz-dev.mendev.ru` |
| Stage | `https://store.raz-stage.rusrazgul.ru` |
| Протокол | REST, JSON |
| Auth | `Authorization: Bearer <token>` (Sanctum) |
| Content-Type | `application/json` |
| Rate limit | нет |
| Режим | Полная логика при включённом модуле `network_catalog` (на dev включён). При выключенном модуле ручка отвечает 200 с неизменённой корзиной |

## Ручки

### POST /api/v1/cart/store: переключить корзину на магазин

**Body:**

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| `storeId` | int | нет | Целевой магазин. Правила: `nullable`, `integer`, существующий активный магазин. Если не передан, сервер берёт первый доступный магазин активного адреса пользователя (тот же резолв, что у `POST /cart/items`) |

**Ответ 200:** объект корзины в той же форме, что `GET /api/v1/cart` (`storeGroups[]`, `weightTotal`, `isHeavy`, `availableShippingMethods`; в marketplace-режиме форма `ClientCartResource`), плюс:

| Поле | Тип | Описание |
|---|---|---|
| `storeId` | int | Магазин, к которому привязана корзина после вызова |
| `removedItems` | array | Позиции, удалённые из корзины как недоступные в целевом магазине, в форме `CartItem` обычной корзины (та же карточка, что в `storeGroups[].items[]`) плюс `productId` и `title`. Пустой массив, если удалять было нечего |
| `hasUnavailableItems` | bool | `true`, если `removedItems` не пуст. Признак для подсказки «Некоторых товаров нет в наличии» |

## Типы

```
removedItems[]                      # CartItem (как в storeGroups[].items[]) + productId, title
├── productId: int                  # id товара (дубль product.id, прежний контракт)
├── title: string                   # название товара (дубль product.title); пустая строка, если карточка товара удалена
├── id: int                         # id удалённой позиции корзины (в корзине её уже нет)
├── product: Product                # карточка товара: id, slug, title, images[], weight, measurementUnit, measurementUnitLabel, price, priceOld, stockQuantity и остальные поля Product из GET /cart
├── quantity: int                   # количество в позиции на момент удаления
├── price: float                    # цена позиции (снапшот магазина, в котором она лежала)
├── priceOld: float|null            # зачёркнутая цена; null, если скидки не было
├── discountPercent: int|null       # процент скидки; null, если скидки не было
├── quantityInStock: int|null       # остаток в магазине, где позиция лежала; null, если магазин не учитывает остатки
├── stockWarning: string|null       # предупреждение по остатку, как в корзине
├── supplements[]                   # добавки позиции (виртуальные и товарные), как в корзине
└── supplementsTotal: int           # стоимость добавок

storeId: int           # целевой магазин; при выключенном модуле network_catalog равен переданному storeId либо 0
hasUnavailableItems: bool
```

Элемент `removedItems[]` собирается тем же ресурсом, что и позиции `storeGroups[].items[]`, поэтому форма полей `product`, `supplements[]` и остальных совпадает с `CartItem` из `GET /cart` один в один. Цена, зачёркнутая цена и остаток даны в контексте магазина, в котором позиция лежала до переключения: это ровно та карточка, которую клиент видел в корзине. Клиент рисует удалённую позицию той же карточкой, что и обычную, только затемнённой.

Форма `storeGroups[]`, `CartItem` и остальных полей корзины: `05_cart.md`, раздел 3.5 (стандартный режим) и 3.6 (marketplace).

## Поведение сервера

| Условие | Что делает сервер |
|---|---|
| Позиция лежит в другом магазине, товар доступен в целевом (активный оффер с ценой и остаток больше нуля, либо магазин не учитывает остатки) | Позиция перепривязывается к целевому магазину. `price` и `priceOld` берутся из оффера целевого магазина, `quantity` не меняется |
| В целевом магазине уже лежит такая же позиция (тот же товар с тем же набором добавок) | Количества суммируются в одну позицию целевого магазина, дубликат удаляется. Такая позиция в `removedItems` не попадает |
| Товар недоступен в целевом магазине: нет оффера, остаток равен нулю (магазин учитывает остатки), карточка неактивна или удалена | Позиция удаляется, товар попадает в `removedItems` |
| Позиция уже в целевом магазине | Остаётся как есть. Если её оффер в этом магазине пропал, удаляется и попадает в `removedItems` |
| У позиции есть товарные добавки (дочерние позиции) | Добавки переезжают вместе с родителем, их цены берутся из офферов целевого магазина. Если у какой-либо добавки нет оффера в целевом магазине, удаляется вся позиция; в `removedItems` попадает только основной товар |
| Остаток в целевом магазине меньше количества позиции, но больше нуля | Позиция остаётся с прежним количеством, у неё заполняется `stockWarning` («Доступно только N шт») |
| В покинутом магазине не осталось позиций | Промокод этого магазина снимается. Platform-wide промокод (`storeId: null`) не трогается |
| Записи корзины у пользователя нет | 200 с пустой формой корзины (как `GET /cart` без корзины) и `storeId`, `removedItems: []`, `hasUnavailableItems: false` |
| Модуль `network_catalog` выключен | Корзина не меняется, `removedItems: []`, `hasUnavailableItems: false` |
| Повторный вызов с тем же `storeId` | Ничего не меняет (ручка идемпотентна) |

Все изменения выполняются в одной транзакции: при ошибке корзина остаётся в исходном состоянии.

## Коды ответов

| Ситуация | HTTP | Тело |
|---|---|---|
| Успех | 200 | Корзина + `storeId`, `removedItems`, `hasUnavailableItems` |
| Запрос без токена | 401 | `{"message": "Unauthenticated."}` |
| `storeId` не передан, а у пользователя нет активного адреса или адрес вне зон доставки | 422 | `{"message": "Не удалось определить магазин: передайте storeId или выберите адрес", "errors": {"storeId": ["Не удалось определить магазин: передайте storeId или выберите адрес"]}}` |
| `storeId` несуществующий или неактивный | 422 | `{"message": "Магазин не найден", "errors": {"storeId": ["Магазин не найден"]}}` |
| `storeId` не целое число | 422 | Стандартное тело валидации Laravel, ключ `storeId` |

## Пример

Запрос:

```bash
curl -s -X POST 'https://store.raz-dev.mendev.ru/api/v1/cart/store' \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"storeId": 835}'
```

Ответ 200 (одна позиция переехала в магазин 835, одна удалена):

```json
{
  "storeGroups": [
    {
      "id": 835,
      "store": {"id": 835, "name": "Разгуляйка, Взлётная 5", "...": "..."},
      "items": [
        {
          "id": 1041,
          "product": {"id": 1024, "title": "Вино Кьянти 0.75", "...": "..."},
          "quantity": 2,
          "price": 890.0,
          "priceOld": null,
          "discountPercent": null,
          "quantityInStock": 12,
          "stockWarning": null,
          "supplements": [],
          "supplementsTotal": 0
        }
      ],
      "deliveryCost": 200.0,
      "assemblyCost": 0,
      "freeDeliveryFrom": 3000,
      "discount": 0.0,
      "promocode": null,
      "weightTotal": 2.5,
      "isHeavy": false,
      "availableFrom": null
    }
  ],
  "weightTotal": 2.5,
  "isHeavy": false,
  "availableShippingMethods": [
    {"method": "own_courier", "label": "Доставка курьером", "available": true, "reason": null},
    {"method": "pickup", "label": "Самовывоз из магазина", "available": true, "reason": null},
    {"method": "cdek", "label": "ПВЗ СДЭК", "available": false, "reason": "Модуль выключен"},
    {"method": "russian_post", "label": "ОПС Почты России", "available": false, "reason": "Модуль выключен"}
  ],
  "storeId": 835,
  "removedItems": [
    {
      "productId": 2210,
      "title": "Сыр Пармезан 200 г",
      "id": 9184,
      "product": {
        "id": 2210,
        "slug": "syr-parmezan-200-g",
        "title": "Сыр Пармезан 200 г",
        "images": [{"id": 501, "url": "https://store.raz-dev.mendev.ru/storage/products/2210/1.jpg", "...": "..."}],
        "weight": 200,
        "measurementUnit": "piece",
        "measurementUnitLabel": "шт",
        "price": 640.0,
        "priceOld": 700.0,
        "discountPercent": 9,
        "stockQuantity": 10,
        "...": "..."
      },
      "quantity": 2,
      "price": 640.0,
      "priceOld": 700.0,
      "discountPercent": 9,
      "quantityInStock": 10,
      "stockWarning": null,
      "supplements": [],
      "supplementsTotal": 0
    }
  ],
  "hasUnavailableItems": true
}
```

Ответ 422 (магазин не определился):

```json
{
  "message": "Не удалось определить магазин: передайте storeId или выберите адрес",
  "errors": {"storeId": ["Не удалось определить магазин: передайте storeId или выберите адрес"]}
}
```

## Обратная совместимость

- Ручка новая, существующие ручки корзины (`GET /cart`, `POST /cart/items`, `PUT /cart/items/{id}`, `DELETE /cart/items/{id}`, промокоды) не менялись.
- Обновление 2026-08-27: элемент `removedItems[]` расширен до формы `CartItem` (поля `id`, `product`, `quantity`, `price`, `priceOld`, `discountPercent`, `quantityInStock`, `stockWarning`, `supplements`, `supplementsTotal`). Прежние поля `productId` и `title` сохранены без изменений: клиент, который разбирает только их, продолжает работать.
- Клиент, который не вызывает ручку, получает прежнее поведение: позиции остаются в магазине покупки, `GET /cart` отдаёт группу прежнего магазина, а `POST /orders` с `storeId` нового магазина отвечает ошибкой пустой корзины, поскольку позиций этого магазина в корзине нет.
- Поле `storeId` в теле необязательное. Все три новых поля ответа присутствуют всегда, в том числе при пустой корзине и при выключенном модуле.
