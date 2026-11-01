# ТЗ: Добавки к товарам (Product Supplements)

## 1. Цель

Добавки — группы опций товара, приходящие из курьерской системы. Storefront:
1. Отдаёт список добавок на карточке товара
2. Принимает выбранные добавки при добавлении товара в корзину и при обновлении позиции
3. Учитывает добавки в суммах корзины и заказа
4. Передаёт добавки в курьерку при создании и дополнении заказа через RabbitMQ

Добавка бывает двух видов:
- **виртуальная** — значение без ссылки на товар каталога, хранится в отдельных таблицах связи с позицией
- **товарная (из каталога)** (новое) — значение ссылается на карточку товара (`supplement_values.product_id`); в корзине и заказе такая добавка материализуется как **дочерняя позиция** (`parent_id` + `source_supplement_value_id`), у неё свой остаток, цена и вес

---

## 2. Контекст

### Где живёт
- Модуль: `storefront_back`
- Затрагивает: Product (отображение), Cart (выбор), Order (фиксация), RabbitMQ (синхронизация)

### Триггеры
- **Inbound RabbitMQ** (routing key `products`): добавки приходят вместе с товаром из курьерки
- **API**: `POST /api/v1/cart/items`, `PUT /api/v1/cart/items/{cartItem}` — выбор добавок
- **API**: `POST /api/v1/orders`, `POST /api/v1/orders/{order}/supplement` — фиксация добавок в заказе
- **Outbound RabbitMQ** (routing key `orders`): добавки уходят в курьерку с заказом

### Связь с системой
- Добавки — справочные данные, управляемые в админке курьерки. Storefront их только принимает и отображает.
- Цепочка: `admin_back → RabbitMQ → storefront_back → API → клиент`

---

## 3. Данные

### 3.1. Таблицы

#### `supplements`
```
id                  BIGINT UNSIGNED PRIMARY KEY
title               VARCHAR(255) NOT NULL
product_id          BIGINT UNSIGNED FK → products.id ON DELETE CASCADE
type                ENUM('one', 'many') NOT NULL DEFAULT 'one'
is_required         BOOLEAN NOT NULL DEFAULT FALSE
soft_deletes
timestamps
```
Индекс: `product_id`.

#### `supplement_values`
```
id                  BIGINT UNSIGNED PRIMARY KEY
title               VARCHAR(255) NOT NULL
price               INT NULL                         -- (новое: было NOT NULL DEFAULT 0)
                                                     -- override-цена; NULL = берётся цена товара-добавки
supplement_id       BIGINT UNSIGNED FK → supplements.id ON DELETE CASCADE
product_id          BIGINT UNSIGNED NULL FK → products.id ON DELETE SET NULL   -- (новое)
max_quantity        INT UNSIGNED NULL                -- (новое) лимит выбора на единицу основного товара
track_stock         BOOLEAN NULL                     -- (новое) override учёта остатка товара-добавки
position            INT NOT NULL DEFAULT 0           -- (новое)
soft_deletes
timestamps
```
Индексы: `product_id`.

`product_id IS NULL` — виртуальное значение, `product_id IS NOT NULL` — товарное (из каталога).

#### `cart_item_supplements`
```
id                  BIGINT UNSIGNED PRIMARY KEY
cart_item_id        BIGINT UNSIGNED FK → cart_items.id ON DELETE CASCADE
supplement_id       BIGINT UNSIGNED FK → supplements.id ON DELETE CASCADE
soft_deletes
timestamps
```
Хранит только **виртуальные** значения. Товарные в этих таблицах не появляются.

#### `join_cart_item_supplements_supplement_values` (pivot)
```
id                          BIGINT UNSIGNED PRIMARY KEY
cart_item_supplement_id     BIGINT UNSIGNED FK → cart_item_supplements.id ON DELETE CASCADE
supplement_value_id         BIGINT UNSIGNED FK → supplement_values.id ON DELETE CASCADE
```

#### `order_item_supplements`
```
id                  BIGINT UNSIGNED PRIMARY KEY
order_item_id       BIGINT UNSIGNED FK → order_items.id ON DELETE CASCADE
supplement_id       BIGINT UNSIGNED FK → supplements.id ON DELETE CASCADE
amount              INT NULL                        -- сумма цен выбранных значений (snapshot)
soft_deletes
timestamps
```

#### `join_order_item_supplements_supplement_values` (pivot)
```
id                              BIGINT UNSIGNED PRIMARY KEY
order_item_supplement_id        BIGINT UNSIGNED FK → order_item_supplements.id ON DELETE CASCADE
supplement_value_id             BIGINT UNSIGNED FK → supplement_values.id ON DELETE CASCADE
amount                          INT NULL            -- цена конкретного значения (snapshot)
```

#### Дочерние позиции (новое)
```
cart_items.parent_id                    BIGINT UNSIGNED NULL FK → cart_items.id ON DELETE CASCADE
cart_items.source_supplement_value_id   BIGINT UNSIGNED NULL FK → supplement_values.id ON DELETE SET NULL

order_items.parent_id                   BIGINT UNSIGNED NULL FK → order_items.id ON DELETE CASCADE
order_items.source_supplement_value_id  BIGINT UNSIGNED NULL FK → supplement_values.id ON DELETE SET NULL
```
Удаление корневой позиции каскадом удаляет дочерние. Удаление значения добавки обнуляет `source_supplement_value_id` у дочерней позиции.

### 3.2. Модели

| Модель | Fillable | Связи и методы |
|--------|----------|----------------|
| `Supplement` | title, product_id, type, is_required | `values()` hasMany(SupplementValue), `product()` belongsTo(Product); cast `is_required` → bool; SoftDeletes |
| `SupplementValue` | title, price, supplement_id, product_id, max_quantity, track_stock, position | `supplement()`, `product()`; `isFromCatalog()`, `effectivePrice()`, `tracksStock()`; SoftDeletes |
| `CartItemSupplement` | cart_item_id, supplement_id | `supplement()`, `values()` belongsToMany (pivot `join_cart_item_supplements_supplement_values`); SoftDeletes |
| `OrderItemSupplement` | order_item_id, supplement_id, amount | `supplement()`, `values()` belongsToMany с `withPivot('amount')`; SoftDeletes |

Константы в `Supplement`: `TYPE_ONE = 'one'`, `TYPE_MANY = 'many'`.

Методы `SupplementValue` (новое):
- `isFromCatalog()` — `product_id !== null`
- `effectivePrice()` — `price`, если он задан; иначе, для товарного значения, эффективная цена товара-добавки (`Product::getEffectivePrice()`, т.е. с учётом акции); иначе `0.0`
- `tracksStock()` — `false` для виртуальных значений и когда товар-добавка недоступен; иначе `track_stock`, если он задан; иначе `! Product::isStockIgnored()` товара-добавки

Связи в существующих моделях:
- `Product`: `supplements()` hasMany(Supplement)
- `CartItem`: `supplements()` hasMany(CartItemSupplement), `parent()`, `childItems()`, `sourceSupplementValue()`, scope `roots()` (`parent_id IS NULL`), аксессор `portions` (`quantity / quantity_step`, для шага 1 — `quantity`)
- `OrderItem`: `supplements()` hasMany(OrderItemSupplement), `parent()`, `childItems()`, `sourceSupplementValue()`, scope `roots()`

### 3.3. API Resources

#### `SupplementResource`
```json
{
  "id": 1,
  "title": "Соус на выбор",
  "type": "one",
  "isRequired": true,
  "values": [ /* SupplementValueResource */ ]
}
```
`values` отдаётся, когда связь `values` загружена; иначе `[]`.

**Фильтрация значений** (новое): товарное значение, у которого учитывается остаток (`tracksStock() === true`), не попадает в `values`, если остаток товара-добавки меньше его шага (`products.quantity < products.quantity_step`). Виртуальные значения и товарные без учёта остатка отдаются всегда.

Сортировка `values` по `position` не применяется — поле принимается из события и хранится, но порядок выдачи им не задаётся.

#### `SupplementValueResource`
Виртуальное значение:
```json
{ "id": 10, "title": "Сырный", "price": 0 }
```
Товарное значение (новое) — те же поля плюс:
```json
{
  "id": 10,
  "title": "Сырный соус",
  "price": 70,
  "isFromCatalog": true,
  "productId": 555,
  "maxQuantity": 3,
  "stockQuantity": 9,
  "imageUrl": "https://.../sauce.jpg",
  "quantity": 1
}
```

| Поле | Тип | Описание |
|---|---|---|
| `price` | int | `effectivePrice()`, приведённая к int |
| `isFromCatalog` | true | присутствует только у товарных значений |
| `productId` | int | id товара-добавки |
| `maxQuantity` | int\|null | может быть null — лимит не задан |
| `stockQuantity` | int\|null | остаток товара-добавки; может быть null, если остаток не учитывается |
| `imageUrl` | string\|null | первое изображение товара-добавки; может быть null |
| `quantity` | int | выбранное количество. В карточке товара всегда `1`; в корзине и заказе — количество дочерней позиции (уже умноженное на порции основного товара) |

#### `CartItemSupplementResource`
```json
{
  "supplementId": 1,
  "title": "Соус на выбор",
  "type": "one",
  "values": [ { "id": 10, "title": "Сырный", "price": 0 } ]
}
```
`title` и `type` берутся из связанной группы добавок; связь `supplement` входит в стандартный набор загрузки корзины.

#### `OrderItemSupplementResource`
```json
{
  "supplementId": 1,
  "title": "Соус на выбор",
  "amount": 0,
  "values": [ { "id": 10, "title": "Сырный", "amount": 0 } ]
}
```
`title` берётся из связанной группы добавок, связь входит в стандартный набор загрузки заказа. `amount` группы — сумма цен выбранных значений на момент оформления, `amount` значения — его цена на момент оформления (snapshot из pivot).

#### `ProductResource`
```json
"supplements": [SupplementResource],
"hasSupplements": true,
"hasRequiredSupplements": true
```
- `supplements` отдаётся, когда связь `supplements` загружена; иначе `[]`.
- `hasSupplements` и `hasRequiredSupplements` (новое) присутствуют в shape только когда загружены счётчики `supplements_count` / `supplements_required_count`. Счётчики загружаются в карточке товара (включая родителя вариативного товара), листингах товаров категории, похожих товарах, поиске, товарах акций, избранном, товарных группах и секциях меню.

#### `CartItemResource`
```json
"supplements": [ /* виртуальные + свёрнутые товарные */ ],
"supplementsTotal": 90
```
Формирование `supplements` (новое — свёртка):
1. Виртуальные добавки позиции (`CartItemSupplementResource`).
2. Товарные добавки, собранные из **дочерних позиций** и сгруппированные по `supplement_id` их исходного значения:
```json
{ "supplementId": 2, "title": "Соус", "type": "many", "values": [SupplementValueResource] }
```
`title` и `type` могут быть null, если группа добавок удалена. Дочерняя позиция с `source_supplement_value_id = null` (значение удалено) в свёртку не попадает.

`supplementsTotal` = сумма цен виртуальных значений × порции корневой позиции + сумма `price × portions` по дочерним позициям.

#### `OrderItemResource`
```json
"supplements": [ /* виртуальные + свёрнутые товарные */ ]
```
Свёртка та же, что в корзине. Формы значений различаются:
- у виртуальных добавок значения содержат `amount` (snapshot);
- у свёрнутых товарных значения отдаются через `SupplementValueResource` — с `price` (актуальная цена, не snapshot), `quantity`, `isFromCatalog`, `productId`, `maxQuantity`, `stockQuantity`, `imageUrl`; поля `amount` у них нет.

---

## 4. Логика

### 4.1. Получение добавок (RabbitMQ inbound)

**Триггер:** событие `products` (`afterCreate` и `afterUpdate`) содержит ключ `supplements`.

**Формат в событии:**
```json
{
  "id": "123",
  "shop_code": "shop1",
  "source": "cron_market",
  "title": "Борщ, 300 г",
  "price": 170,
  "supplements": [
    {
      "title": "Соус на выбор",
      "type": "one",
      "is_required": true,
      "values": [
        { "title": "Кетчуп", "price": 0 },
        {
          "title": "Сырный соус",
          "product_external_id": "sauce-guid",
          "max_quantity": 3,
          "track_stock": null,
          "position": 1
        }
      ]
    }
  ]
}
```

**Поведение `ProductEventTransformer::processSupplements()`:**
- Обработка запускается только если ключ `supplements` присутствует и не равен null. Событие без него существующие добавки не трогает.
- Синхронизация полная: все добавки товара удаляются физически (`forceDelete`, значения уходят каскадом), затем создаются заново из события. Всё внутри транзакции.
- Поля группы: `title`, `type` (по умолчанию `one`), `is_required` (по умолчанию `false`).
- Поля значения: `title` (по умолчанию `''`), `price` (если ключ `price` отсутствует — сохраняется `null`), `max_quantity`, `track_stock`, `position` (по умолчанию `0`).
- `product_external_id` (новое) резолвится в локальный `product_id` через `event_sync_metadata` по паре (`source`, `shop_code`) события. Если товар-добавка ещё не синхронизирован — значение создаётся с `product_id = null` и пишется warning в лог.
- По завершении пишется лог `Product supplements synced` с числом добавок.

### 4.2. Отображение добавок в API товара

**Триггер:** `GET /api/v1/products/{id}`

Связь `supplements.values` загружается в карточке товара (в том числе для родителя вариативного товара). В списках (каталог, поиск, акции, избранное, товарные группы) связь не загружается — `supplements` приходит как `[]`, а признаки наличия добавок передаются через `hasSupplements` / `hasRequiredSupplements`.

Товарное значение с учётом остатка при нулевом (меньше шага) остатке товара-добавки в `values` не отдаётся (см. 3.3).

### 4.3. Добавление в корзину с добавками

**Триггер:** `POST /api/v1/cart/items`

**Payload:**
```json
{
  "productId": 123,
  "quantity": 1,
  "supplements": [
    { "supplementId": 1, "valueIds": [10] },
    { "supplementId": 2, "valueQuantities": [ { "valueId": 20, "quantity": 2 } ] }
  ]
}
```

| Поле | Тип | Обязательный | Валидация |
|------|-----|:---:|-----------|
| supplements | array | ❌ | `sometimes`, `array` + `CartSupplementsRule` |
| supplements.*.supplementId | int | ✅ | `exists:supplements,id` |
| supplements.*.valueIds | int[] | ❌ | `sometimes`, `min:1`, элементы `exists:supplement_values,id` |
| supplements.*.valueQuantities | array | ❌ | `sometimes`, `min:1` |
| supplements.*.valueQuantities.*.valueId | int | ✅ | `exists:supplement_values,id` |
| supplements.*.valueQuantities.*.quantity | int | ✅ | `min:1` |

Ключ `values` принимается как deprecated-алиас `valueIds` (новое): в `prepareForValidation` он копируется в `valueIds`, если `valueIds` не передан.

**Нормализация выбора** (`HandlesSupplements::normalizeSupplements`, новое): `valueQuantities` имеет приоритет над `valueIds`/`values`; количество приводится к `max(1, quantity)`; при передаче через `valueIds` количество каждого значения равно 1.

**Проверки `CartSupplementsRule` (все — 422 с `{message, errors}`):**

| Условие | Сообщение |
|---|---|
| `supplements` не массив | `Добавки должны быть массивом` |
| обязательная добавка товара отсутствует в payload или её набор значений пуст | `Не все обязательные добавки выбраны` |
| `supplementId` не принадлежит товару, либо `valueId` не принадлежит своей группе | `Добавка не принадлежит данному товару` |
| у переданной группы пустой набор значений | `Выберите значение добавки «{title группы}»` |
| для `type=one` передано больше одного значения | `Для добавки «{title группы}» можно выбрать только одно значение` |
| количество значения больше `max_quantity` (новое) | `Для добавки «{title значения}» максимум {max_quantity} шт.` |
| товарное значение с учётом остатка: `количество × порции основного товара > остатка товара-добавки` (новое) | `Добавки «{title значения}» недостаточно на складе` |

Порции основного товара для проверки остатка считаются из `quantity` запроса: при `quantity_step > 1` — `quantity / quantity_step`, иначе `quantity`.

Если `supplements` не передан вовсе, а у товара есть добавка с `is_required = true` — `AddToCartAction` отдаёт 422 `Не все обязательные добавки выбраны`.

**Поведение `AddToCartAction`:**
1. Выбор нормализуется и делится на виртуальные и товарные значения (`splitSupplements`).
2. Строится ключ набора добавок (новое): виртуальные — `supplementId:отсортированные valueIds`, товарные — `#productId:количество_на_единицу`; части отсортированы.
3. Среди **корневых** позиций корзины (`parent_id IS NULL`) с тем же `product_id` ищется позиция с совпадающим ключом (выборка под `lockForUpdate`).
4. **Совпадение найдено** — количества складываются, позиции сливаются:
   - до обновления проверяется остаток каждой дочерней товарной добавки с учётом новых порций: `на_единицу = round(quantity дочерней / старые порции)`, `новое = на_единицу × новые порции`; при `tracksStock()` и превышении остатка — 422 `Товар '{title}' недоступен в нужном количестве. Доступно: {N}, запрошено: {M}` (ошибка в `errors.quantity`);
   - после обновления корневой позиции количество каждой дочерней пересчитывается как `на_единицу × новые порции` (новое).
5. **Совпадения нет** — создаётся новая корневая позиция, к ней:
   - виртуальные значения → `CartItemSupplement` + pivot;
   - товарные значения → дочерние позиции корзины (новое): `parent_id` корневой позиции, `source_supplement_value_id`, `product_id` товара-добавки, `store_id` товара-добавки (при его отсутствии — магазин корневой позиции), `quantity = выбранное_на_единицу × порции корневой позиции`, `price = effectivePrice()` значения, `price_old = null`.
6. Остаток самого товара проверяется по суммарному количеству корневой позиции (кроме режима игнорирования остатка) — при превышении 422 с тем же текстом `InsufficientStockException`.

Транзакция выполняется с 3 попытками при дедлоке; при гонке создания корзины (`carts_user_id_unique`) вся операция повторяется до 3 раз.

Массовое добавление `POST /api/v1/cart/items/batch` поля `supplements` не принимает и добавки не создаёт.

### 4.4. Обновление позиции корзины

**Триггер:** `PUT /api/v1/cart/items/{cartItem}`

| Поле | Тип | Обязательный | Валидация |
|------|-----|:---:|-----------|
| quantity | int | ✅ | `min:1`, `max:99999` |
| supplements | array | ❌ | `sometimes`, `array` + `CartSupplementsRule` по `product_id` позиции |
| supplements.*.supplementId | int | ✅ | `exists:supplements,id` |
| supplements.*.valueIds | int[] | ✅ | `required`, `min:1`, элементы `exists:supplement_values,id` |

Отличие от добавления: в `PUT` `valueIds` **обязателен** внутри каждой переданной группы, а ключ `valueQuantities` в правилах не описан. Запрос, где у группы передан только `valueQuantities`, отклоняется с 422 `Выберите значения добавки`. Если переданы оба ключа, валидация проходит по `valueIds`, а количества берутся из `valueQuantities`. Алиас `values` → `valueIds` работает так же, как при добавлении.

**Поведение `UpdateCartItemAction`:**
- `supplements` не передан: набор добавок сохраняется; дочерние позиции пересчитываются пропорционально изменению порций (`на_единицу × новые порции`, новое). До обновления для дочерних с учётом остатка выполняется проверка остатка — при превышении 422 `InsufficientStockException`.
- `supplements` передан: виртуальные добавки позиции и все её дочерние позиции удаляются, набор создаётся заново из payload; количества дочерних считаются от **новых** порций. Отдельной проверки остатка в этой ветке нет — работает только проверка из `CartSupplementsRule`.
- Количество кратно шагу товара, иначе 422 `Количество должно быть кратно {step}`; остаток самого товара проверяется как обычно.

Удаление корневой позиции (`DELETE /api/v1/cart/items/{cartItem}`) каскадом удаляет её дочерние позиции.

### 4.5. Расчёт сумм с добавками

```
позиция = (price + Σ цены виртуальных значений) × портции
         + Σ (price × portions) по дочерним позициям
```

- `CartCalculator::calculateSubtotalWithSupplements()` считает сумму по этой формуле; она же используется для порога бесплатной доставки (`freeDeliveryFrom`) и для отображаемой цены доставки.
- `CartItemResource.supplementsTotal` = виртуальные значения × порции + `Σ (price × portions)` дочерних позиций.
- **Промокод**: база скидки — `price × portions` по позициям без собственной скидки, подходящим под привязку промокода. Виртуальные добавки в базу не входят. Дочерние позиции товарных добавок в корзине в базу **не входят** (группы строятся по корневым позициям), а при создании заказа `CreateOrderAction::calculateDiscount()` считает по всем позициям корзины, включая дочерние.
- **Бонусы**: дочерние позиции заказа фиксируются с `eligible_for_bonus = false` — на товарные добавки бонусы не начисляются. Предварительная оценка бонусов в `CreateOrderAction::previewBonus()` при этом считает eligible-сумму по всем позициям корзины, включая дочерние.
- **Вес и «тяжёлый заказ»**: `weightTotal` корзины и признак тяжёлого заказа считаются по корневым позициям; дочерние в вес не входят.
- **Готовность (`availableFrom`)**: считается по полному множеству позиций магазина, включая дочерние.

### 4.6. Отдача корзины

- Позиции верхнего уровня — только корневые (`parent_id IS NULL`); дочерние отдаются свёрнутыми внутри `supplements[]` соответствующей корневой позиции (новое).
- Группировка по магазинам, `weightTotal`, `itemsCount` — по корневым позициям.
- В marketplace-режиме (`ClientCartResource`) дочерние позиции так же исключены из групп по датам и из `totalItemsCount`.
- Набор связей для сериализации задан в `Cart::RESPONSE_RELATIONS`: `items.product.supplements.values`, `items.supplements.supplement`, `items.supplements.values`, `items.childItems.sourceSupplementValue.product.images`.
- Из-за загрузки `items.product.supplements.values` вложенный в позицию корзины `product` содержит полный справочник добавок товара (`supplements[]` со всеми доступными значениями), а `supplements[]` самой позиции — только выбранные.

### 4.7. Создание заказа с добавками

**Триггер:** `POST /api/v1/orders`

`CreateOrderAction`:
1. `subtotal` считается по всем позициям корзины магазина: `(price + виртуальные значения) × портции`; дочерние позиции дают `price × portions` как обычные позиции. Этот же `subtotal` проверяется против минимальной суммы заказа и порога бесплатной доставки.
2. `orders.items_count` = число **корневых** позиций корзины (новое) — дочерние в счётчик не входят.
3. Для каждой корневой позиции создаётся `OrderItem`: `total = (price + виртуальные значения за порцию) × портции`.
4. Виртуальные добавки копируются в `OrderItemSupplement`: `amount` = сумма цен выбранных значений; в pivot каждого значения пишется его цена (snapshot).
5. Для каждой дочерней позиции корзины создаётся **дочерний `OrderItem`** (новое): `parent_id` корневой позиции заказа, `source_supplement_value_id`, `product_id`, снапшот `title`/`sku`/`price`/`price_old`/`vat_rate`/`image_url` товара-добавки, `total = price × portions`, `eligible_for_bonus = false`. Для товаров в килограммах количество конвертируется в граммы, как и у корневых позиций.
6. Резервирование (`ReserveProductsAction`) проходит по всем позициям, включая дочерние; для дочерней позиции, у которой `SupplementValue::tracksStock() === false`, проверка остатка и списание пропускаются (новое). Для остальных действует общее правило: при нехватке остатка — `InsufficientStockException` (422), списание количества выполняется только в режиме `fixed`.
7. Ограничение «только самовывоз» проверяется по всем позициям корзины, включая дочерние.

**Отдача заказа** (`GET /api/v1/orders`, `GET /api/v1/orders/{order}`):
- `items` — только корневые позиции; дочерние свёрнуты в `supplements[]` (новое).
- `itemsCount` берётся из `orders.items_count`, `weightTotal` — по корневым позициям.
- Связи загружаются как `items.supplements.supplement`, `items.supplements.values`, `items.childItems.sourceSupplementValue.product.images`.
- В объединённом клиентском заказе marketplace-режима (`ClientOrderResource`) свёртка не применяется: дочерние позиции отдаются в `items` наравне с корневыми и учитываются в `itemsCount`.

### 4.8. Дополнение заказа с добавками

**Триггер:** `POST /api/v1/orders/{order}/supplement`

`SupplementOrderAction` обрабатывает все позиции корзины магазина заказа:
- Ключ набора добавок строится **только по виртуальным** добавкам позиции.
- Если в заказе есть позиция с тем же `product_id` и тем же ключом — увеличивается её `quantity`, `total` пересчитывается как `price × портции`.
- Иначе создаётся новая позиция заказа, в неё копируются виртуальные добавки (`OrderItemSupplement` + pivot со снапшотом цен).
- Дочерние позиции корзины (товарные добавки) переносятся как **самостоятельные позиции заказа**: `parent_id` и `source_supplement_value_id` при дополнении не заполняются, поэтому такие добавки в `supplements[]` не сворачиваются.
- `subtotal` заказа пересчитывается как `Σ (item.total + amount виртуальных добавок × item.quantity)`.
- `items_count` пересчитывается как общее число позиций заказа, включая дочерние, созданные при первоначальном оформлении.

### 4.9. RabbitMQ outbound (заказы)

`OrderEventTransformer::buildItems()` отдаёт **все** позиции заказа, включая дочерние. У каждой позиции:

```json
{
  "id": "51",
  "line_id": "51",
  "parent_line_id": "50",
  "source_supplement_value_external": "10",
  "product_id": "sauce-guid",
  "product_name": "Сырный соус",
  "unit_price": 70,
  "quantity": 2,
  "line_total": 140
}
```

| Поле | Описание |
|---|---|
| `line_id` | id позиции как строка |
| `parent_line_id` | `line_id` корневой позиции; null у корневых (новое) |
| `source_supplement_value_external` | локальный id `supplement_value`, из которого создана дочерняя позиция, как строка; null у корневых (новое) |
| `quantity` | количество в порциях |

Виртуальные добавки уходят отдельным ключом `supplements`, который присутствует **только если** у позиции есть записи `OrderItemSupplement`:
```json
"supplements": [
  {
    "supplement_id": 2,
    "title": "Допы",
    "amount": 90,
    "values": [
      { "supplement_value_id": 20, "title": "Аджика", "amount": 50 },
      { "supplement_value_id": 21, "title": "Сметана с чесноком", "amount": 40 }
    ]
  }
]
```

Формат используется в событиях `order_created`, `order_supplemented` и в последующих обновлениях заказа.

При приёме обновлений заказа из курьерки состав добавок не меняется; локальный пересчёт `total` позиции использует `(price + Σ amount добавок) × портции`, а `items_count` считается по корневым позициям.

---

## 5. Интеграции

### 5.1. Inbound `products` — требования к событию
- Ключ `supplements` — массив групп; отсутствие ключа (или `null`) означает «добавки не менять».
- Группа: `title`, `type` (`one`/`many`), `is_required`, `values[]`.
- Значение: `title`, `price` (отсутствие ключа = «цена берётся из товара-добавки»), `product_external_id` (для товарной добавки), `max_quantity`, `track_stock`, `position`.
- Товар-добавка резолвится по `product_external_id` + `source` и `shop_code` события. Если он ещё не синхронизирован, значение сохраняется без ссылки на товар и ведёт себя как виртуальное (цена — `price` либо 0, остаток не учитывается).

### 5.2. Outbound `orders`
- Дочерние позиции и связки `line_id`/`parent_line_id`/`source_supplement_value_external` передаются всегда.
- Виртуальные добавки передаются в `items[].supplements` (см. 4.9).
- Получатель — purchase_back / admin_back: локальный `supplement_value` резолвится по паре (товар, родительская позиция).

---

## 6. Нефункциональные требования

### Транзакции
- Добавление в корзину, обновление позиции и создание заказа выполняются в транзакции; дочерние позиции и записи добавок создаются внутри неё.
- Синхронизация добавок из события — отдельная транзакция на `forceDelete` + создание.
- Конкурентные операции с корзиной защищены `lockForUpdate` по позициям; транзакции повторяются до 3 раз при дедлоке.

### Производительность
- `supplements.values` загружается на карточке товара; в списках вместо связи используются счётчики `supplements_count` / `supplements_required_count`.
- Для корзины и заказа связи заданы в `Cart::RESPONSE_RELATIONS` и в загрузках `OrderController`.
- При свёртке товарных добавок группа `Supplement` резолвится точечным запросом по `supplement_id` на каждую группу дочерних позиций.
- Добавки не индексируются в Meilisearch.

### Идемпотентность
- Повторное событие `products` c тем же составом добавок даёт тот же результат (полное пересоздание).
- Повторное добавление товара с тем же набором добавок увеличивает количество существующей позиции, а не создаёт дубль.
- Передача `supplements` в `PUT /cart/items/{id}` полностью заменяет текущий набор.

### Граничные случаи
- У товара без добавок `supplements` = `[]`.
- Клиент не передал `supplements` для товара с обязательной добавкой — 422.
- Значение добавки удалено в справочнике: `source_supplement_value_id` дочерней позиции обнуляется, позиция в свёртке `supplements[]` не показывается, но остаётся в расчёте сумм как позиция корзины/заказа.

---

## 7. Состав реализации

**Модели:** `Supplement`, `SupplementValue`, `CartItemSupplement`, `OrderItemSupplement`; связи и дочерние позиции в `Product`, `CartItem`, `OrderItem`.

**Resources:** `SupplementResource`, `SupplementValueResource`, `CartItemSupplementResource`, `OrderItemSupplementResource`; поля добавок в `ProductResource`, `CartItemResource`, `OrderItemResource`; фильтрация корневых позиций в `CartResource`, `ClientCartResource`, `OrderResource`.

**Валидация:** `App\Rules\CartSupplementsRule`; поля `supplements` в `AddToCartRequest` и `UpdateCartItemRequest`.

**Actions и сервисы:** трейт `App\Actions\Cart\Concerns\HandlesSupplements` (`normalizeSupplements`, `splitSupplements`, `createCatalogChildren`, `createCartItemSupplements`), `AddToCartAction`, `UpdateCartItemAction`, `CreateOrderAction`, `SupplementOrderAction`, `ReserveProductsAction`, `CartCalculator`.

**RabbitMQ:** `ProductEventTransformer::processSupplements()` и резолв товара-добавки по external id; `OrderEventTransformer::buildItems()`.

**Миграции:**
- `2026_03_13_000001_create_supplements_table`
- `2026_03_13_000002_create_supplement_values_table`
- `2026_03_13_000003_create_cart_item_supplements_table`
- `2026_03_13_000004_create_join_cart_item_supplements_supplement_values_table`
- `2026_03_13_000005_create_order_item_supplements_table`
- `2026_03_13_000006_create_join_order_item_supplements_supplement_values_table`
- `2026_06_23_000001_add_catalog_fields_to_supplement_values_table` (новое)
- `2026_06_23_000002_add_parent_to_cart_items_table` (новое)
- `2026_06_23_000003_add_parent_to_order_items_table` (новое)

**Тесты:**
- `tests/Feature/Api/V1/Product/ProductSupplementsTest.php`
- `tests/Feature/Api/V1/Product/HasRequiredSupplementsTest.php`
- `tests/Feature/Api/V1/Cart/CartSupplementsTest.php`
- `tests/Feature/Api/V1/Order/OrderSupplementsTest.php`
- `tests/Feature/Cart/CatalogSupplementTest.php`
- `tests/Feature/Order/CatalogSupplementOrderTest.php`
- `tests/Feature/RabbitMQ/ProductSupplementsSyncTest.php`
- `tests/Feature/RabbitMQ/SupplementProductSyncTest.php`
