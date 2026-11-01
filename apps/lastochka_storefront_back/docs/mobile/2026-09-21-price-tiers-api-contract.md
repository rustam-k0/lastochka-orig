# Цены от количества (MID-308)

Контракт изменённого REST API витрины (`mendev_storefront_back`) для сайта и клиентского приложения. Описывает только ручки и поля.

## Что изменилось

1. `GET /app-settings`: новое поле `priceTiers` (boolean).
2. Объект `Product` во всех ручках, где он отдаётся: новое поле `priceTiers` (массив). Поля `price`, `priceOld`, `discountPercent` остаются, их типы не меняются.
3. Элементы `Product.variants[]`: новое поле `priceTiers`.
4. Товары акции (`GET /promotions/{promotion}/products`): новое поле `priceTiers`.
5. Позиция корзины `CartItem` во всех ручках корзины: новое поле `priceTiers`. Поля `price`, `priceOld`, `discountPercent` остаются, их типы не меняются.
6. Ручки заказов (`/orders`, `/client-orders`): без изменений, новых полей нет.

Все изменения обратно совместимы: новые поля только добавлены. Когда `priceTiers` в `/app-settings` равен `false`, массив `priceTiers` у товаров и позиций корзины всегда пустой.

## Существующие поля, значения которых зависят от функции

Кроме новых полей, при включённой функции меняются значения нескольких уже существующих полей: они начинают учитывать цену для количества.

| Ручка | Поле | Что меняется |
|---|---|---|
| `GET /estimates`, `GET /estimates/{estimate}`, `POST /estimates/{estimate}/clone`, `GET /estimates/shared/{token}`, `GET /estimates/{estimate}/export` | `totalAmount` (JSON) и сумма/итог в XLSX и PDF | Сумма позиции считается по цене для её количества (ступень, если применена), а не только по обычной цене товара |
| `GET /comparisons`, `GET /comparisons/{categoryId}` | `price` | Для товара с лесенкой отдаётся цена за одну порцию по резолверу цены, а не обычная цена или акция |

## База

| | |
|---|---|
| Base URL (dev) | `https://store.raz-dev.mendev.ru/api/v1` |
| Base URL (stage) | `https://store.raz-stage.rusrazgul.ru/api/v1` |
| Протокол | REST, `application/json` |
| Авторизация | Каталог и `/app-settings`: не требуется, заголовок `Authorization: Bearer <token>` опционален. Корзина: `Authorization: Bearer <sanctum-token>` обязателен |
| Rate limit | Без изменений |

## Тип PriceTier

```json
{ "minQuantity": 5, "price": 199 }
```

| Поле | Тип | Описание |
|---|---|---|
| `minQuantity` | number | Порог количества в единицах поля `quantity` корзины (для товара с `quantityStep = 200` и `measurementUnit = "gram"` это граммы, например `2000`). Минимальное количество в единицах поля `quantity`, с которого действует цена; для товаров с шагом продажи всегда кратно шагу |
| `price` | number | Цена за порцию, в той же семантике, что поле `price` товара (цена строки корзины = `price × quantity / quantityStep`) |

Массив отсортирован по возрастанию `minQuantity`. Пустой массив: у товара в магазине контекста нет цен от количества.

## Ручки

### 1. Настройки приложения

```
GET /app-settings
```

Авторизация не требуется.

`200` (показано только новое поле):

```json
{ "data": { "priceTiers": true } }
```

| Поле | Тип | Описание |
|---|---|---|
| `priceTiers` | boolean | `true`: у товаров и позиций корзины может быть заполнен массив `priceTiers`. `false`: массив всегда пустой |

### 2. Карточка товара

```
GET /products/{product}
GET /products/slug/{slug}
```

| Параметр | Где | Тип | Обяз. | Описание |
|---|---|---|---|---|
| `product` | path | int | да | ID товара |
| `slug` | path | string | да | Слаг товара |
| `storeId` | query | int | нет | Магазин контекста (сетевой каталог), как раньше |

`200` (показаны поля, относящиеся к задаче):

```json
{
  "data": {
    "id": 1024,
    "price": 279,
    "priceOld": null,
    "discountPercent": null,
    "quantityStep": 1,
    "measurementUnit": "piece",
    "priceTiers": [
      { "minQuantity": 1, "price": 279 },
      { "minQuantity": 2, "price": 249 },
      { "minQuantity": 5, "price": 199 }
    ],
    "variants": [
      { "id": 1025, "price": 279, "priceOld": null, "priceTiers": [] }
    ]
  }
}
```

| Поле | Тип | Описание |
|---|---|---|
| `price` | number | Цена за одну порцию |
| `priceOld` | number или null | Зачёркнутая цена за одну порцию |
| `discountPercent` | int или null | Процент скидки за одну порцию |
| `priceTiers` | PriceTier[] | См. «Тип PriceTier» |
| `variants[].priceTiers` | PriceTier[] | То же для вариации; поле `variants` отдаётся только у вариативного товара, как раньше |

Коды: `200`, `404` (товар не найден или не продаётся в магазине контекста). Без изменений.

### 3. Ручки, отдающие список Product

Поле `priceTiers` добавлено в каждый объект `Product` этих ответов. Параметры запросов и остальные поля без изменений.

```
GET /stores/{store}/categories/{category}/products
GET /stores/{store}/categories/slug/{slug}/products
GET /stores/{store}/categories            (при includeProducts=1: data[].products[])
GET /products/{product}/similar
GET /products/{product}/related           (data[].products[])
GET /products/search/barcode/{barcode}
GET /search/products                      (data[] либо data[].products[])
GET /search/stores/{store}/products
GET /product-groups                       (при withProducts=1: data[].products[])
GET /product-groups/{productGroup}/products
GET /stores/{store}/product-groups/slug/{slug}/products
GET /stores/{storeId}/menu-sections       (data[].products[])
GET /favorites                            (auth: Bearer)
GET /estimates/{estimate}                 (auth: Bearer, data.items[].product)
GET /estimates/shared/{token}             (data.items[].product)
```

Пример элемента списка:

```json
{
  "id": 1024,
  "price": 100,
  "priceOld": null,
  "quantityStep": 200,
  "measurementUnit": "gram",
  "priceTiers": [ { "minQuantity": 2000, "price": 90 } ]
}
```

Коды ответов без изменений.

### 4. Товары акции

```
GET /promotions/{promotion}/products
```

| Параметр | Где | Тип | Обяз. | Описание |
|---|---|---|---|---|
| `promotion` | path | string | да | Слаг акции |

`200` (элемент `data[]`, показаны поля, относящиеся к задаче):

```json
{
  "id": 1024,
  "price": 290,
  "priceOld": 290,
  "discountPercent": null,
  "priceTiers": [
    { "minQuantity": 1, "price": 290 },
    { "minQuantity": 3, "price": 250 }
  ]
}
```

| Поле | Тип | Описание |
|---|---|---|
| `price` | number | Цена за одну порцию |
| `priceOld` | number | Зачёркнутая цена за одну порцию; поле не бывает `null` (как раньше) |
| `discountPercent` | int или null | Процент скидки |
| `priceTiers` | PriceTier[] | См. «Тип PriceTier» |

Коды: `200`, `404`. Без изменений.

### 5. Корзина

```
GET    /cart
POST   /cart/items
PUT    /cart/items/{cartItem}
DELETE /cart/items/{cartItem}
POST   /cart/promocode
DELETE /cart/promocode
POST   /cart/store
```

Авторизация: `Authorization: Bearer <sanctum-token>`. Тела запросов без изменений. `POST /cart/items/batch` позицию корзины в ответе не отдаёт (`added`, `skipped`, `skippedProducts`), его ответ без изменений.

`POST /cart/items`, body:

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| `productId` | int | да | ID товара |
| `quantity` | int | да | Количество в единицах корзины, кратно `quantityStep` |
| `storeId` | int | нет | Магазин покупки (сетевой каталог) |
| `supplements` | array | нет | Добавки, как раньше |

`PUT /cart/items/{cartItem}`, body:

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| `quantity` | int | да | Новое количество позиции |
| `supplements` | array | нет | Добавки, как раньше |

`200`, позиция корзины `storeGroups[].items[]` (в режиме маркетплейса `dateGroups[]...items[]`), показаны поля, относящиеся к задаче:

```json
{
  "id": 501,
  "quantity": 5,
  "price": 199,
  "priceOld": 279,
  "discountPercent": 29,
  "priceTiers": [
    { "minQuantity": 1, "price": 279 },
    { "minQuantity": 2, "price": 249 },
    { "minQuantity": 5, "price": 199 }
  ],
  "product": {
    "id": 1024,
    "price": 279,
    "priceOld": null,
    "priceTiers": [
      { "minQuantity": 1, "price": 279 },
      { "minQuantity": 2, "price": 249 },
      { "minQuantity": 5, "price": 199 }
    ]
  }
}
```

| Поле | Тип | Описание |
|---|---|---|
| `price` | number | Цена за порцию для текущего `quantity` позиции |
| `priceOld` | number или null | Зачёркнутая цена за порцию для текущего `quantity` позиции |
| `discountPercent` | int или null | Процент скидки позиции |
| `priceTiers` | PriceTier[] | См. «Тип PriceTier»; у товарных добавок всегда `[]` |
| `product.price` | number | Цена товара за одну порцию (как в карточке) |
| `product.priceTiers` | PriceTier[] | То же, что `priceTiers` позиции |

`POST /cart/store`: элементы `removedItems[]` имеют ту же форму позиции корзины и тоже содержат `priceTiers`.

Коды ответов ручек корзины без изменений: `200`, `401`, `403`, `404`, `422`.

### 6. Заказы

```
GET  /orders
GET  /orders/{order}
POST /orders
POST /orders/{order}/supplement
```

Без изменений: новых полей в запросах и ответах нет. Поля позиции заказа `price`, `priceOld`, `total` заполняются как раньше.
