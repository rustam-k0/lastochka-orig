# ТЗ: Корзина

## 1. Цель

Корзина покупок с группировкой по магазинам (стандартный режим) или по датам доставки (marketplace-режим) и поддержкой промокодов. Корзина — локальная сущность, не синхронизируется с внешними системами.

## 2. Контекст

- **Модуль:** Cart
- **Триггер:** Пользователь добавляет товары в корзину
- **Особенность:** В стандартном режиме товары из разных магазинов группируются отдельно; в marketplace-режиме магазины в контракте не раскрываются, группировка идёт по дате доставки позиции
- **Синхронизация:** Нет (локальная сущность)
- **Авторизация:** все ручки под `auth:sanctum`; throttle-мидлвар на ручках корзины не навешен

### 2.1 Фича-флаги, влияющие на контракт

| Флаг (модуль) | Влияние на корзину |
|---|---|
| `marketplace_mode` | Определяет форму ответа: `CartResource` (`storeGroups`) или `ClientCartResource` (`dateGroups`). Открывает `GET /cart/delivery-summary`, разрешает `storeId=null` в промокодах, добавляет `deliveryDate` в позицию |
| `assembly_cost` | При выключенном флаге `assemblyCost` в группе всегда `0` |
| `heavy_orders`, `courier_assignment` | При выключенных обоих `isHeavy` всегда `false` |
| `b2b` | При включённом флаге B2C-клиент не может добавить товар из B2B-магазина (403) |
| `product_ready_time` | При выключенном флаге `availableFrom` всегда `null` |
| `delivery_cdek`, `delivery_russian_post` | Определяют доступность методов `cdek` / `russian_post` в `availableShippingMethods` |

## 3. Данные

### 3.1 Модель Cart

```
carts
├── id: bigint PK
├── user_id: bigint FK → users.id UNIQUE (cascade delete)
├── created_at: timestamp
└── updated_at: timestamp
```

### 3.2 Модель CartItem

```
cart_items
├── id: bigint PK
├── parent_id: bigint FK → cart_items.id NULL (cascade delete)   // (новое) дочерняя позиция = товарная добавка
├── source_supplement_value_id: bigint FK → supplement_values.id NULL (null on delete)  // (новое)
├── cart_id: bigint FK → carts.id
├── product_id: bigint FK → products.id
├── store_id: bigint FK → stores.id         // Денормализация
├── delivery_date: date NULL                // (новое) желаемая дата доставки позиции
├── quantity: int NOT NULL DEFAULT 1
├── price: decimal(12,2) NOT NULL           // Цена на момент добавления, с копейками
├── price_old: decimal(12,2) NULL           // (новое) оригинальная цена до скидки (снапшот), null = скидки нет
├── created_at: timestamp
└── updated_at: timestamp
```

**Индексы:**
- `cart_id, store_id` — для группировки
- `cart_id, product_id` — **не уникальный** (уникальность снята: одна и та же карточка может лежать в корзине несколько раз с разными наборами добавок)

**Порции (`portions`):** вычисляемый атрибут позиции — `quantity / products.quantity_step` для товаров с шагом > 1 (весовые/литровые), иначе `quantity`. Все денежные расчёты идут по порциям, а не по «сырому» `quantity`.

**Вес позиции (граммы):** для товаров с `quantity_step > 1` вес равен `quantity` (граммы/мл), для штучных — `products.weight × quantity`; если у штучного товара нет `weight`, вес позиции не определён.

### 3.3 Модель CartPromocode

```
cart_promocodes
├── id: bigint PK
├── cart_id: bigint FK → carts.id
├── store_id: bigint FK → stores.id NULL     // (новое) null = platform-wide промокод на всю корзину
├── promocode_id: bigint FK → promocodes.id
├── created_at: timestamp
└── updated_at: timestamp
```

Уникальность `(cart_id, store_id)` снята на MySQL, чтобы platform-wide запись (`store_id = null`) сосуществовала со store-scoped. Один промокод на магазин и один platform-wide обеспечиваются на уровне приложения (`updateOrCreate` по `store_id`).

### 3.4 Модель Promocode

```
promocodes
├── id: bigint PK
├── title: string(200) NOT NULL              // Название для админки
├── code: string(50) UNIQUE NOT NULL         // Ключевое слово
├── type: enum('percent', 'fixed') NOT NULL
├── value: int NOT NULL                      // % или рубли (модель отдаёт как decimal:2)
├── min_order_amount: int NULL               // Минимальная сумма заказа (модель — decimal:2)
├── store_id: bigint FK → stores.id NULL     // NULL = подходит любому магазину
├── scope: string(32) DEFAULT 'store'        // (новое) 'store' | 'platform'
├── eligible_amount_threshold: decimal(10,2) NULL   // (новое)
├── source: string NULL                      // (новое) источник импорта
├── external_id: string NULL                 // (новое) id во внешней системе
├── max_uses: int NULL                       // Лимит использований всего
├── max_uses_per_client: int NULL            // (новое) лимит на клиента
├── used_count: int DEFAULT 0              // счётчик всех оформлений, в лимитах не участвует
├── starts_at: datetime NOT NULL             // Дата начала действия
├── ends_at: datetime NOT NULL               // Дата окончания действия
├── is_active: boolean DEFAULT true
├── binding_type: enum('all','products','categories') DEFAULT 'all'   // (новое)
├── created_at: timestamp
└── updated_at: timestamp
```

Привязка (`binding_type`): `all` — любой товар; `products` — только товары из `promocode_products`; `categories` — только товары, чьи категории пересекаются с `promocode_categories`.

Использования промокода учитываются в `promocode_usages` (проверка `max_uses` и `max_uses_per_client`). Использования по отменённым заказам в лимиты не входят (`PromocodeUsage::scopeActive()`); platform-wide промокод маркетплейса освобождается, когда отменены все sub-orders.

**Важно:** промокод применяется только к товарам **БЕЗ скидки** — у которых `price_old` пуст и нет активной акции — **и** подходящим под привязку.

**Источник данных:** промокоды создаются в курьерской системе:
- Мерчант — создаёт промокод для своего магазина (`store_id` автоматически)
- Админ — может выбрать мерчанта или создать универсальный

### 3.5 Структура ответа API — стандартный режим

`CartResource`, **без обёртки `data`**:

```
Cart
├── storeGroups: CartStoreGroup[]
│   ├── id: int (store_id)
│   ├── store: Store               // + store.deliveryInfo {deliveryPrice, minOrderAmount, freeDeliveryFrom}
│   ├── items: CartItem[]          // только корневые позиции
│   ├── deliveryCost: float
│   ├── assemblyCost: int          // (новое)
│   ├── freeDeliveryFrom: int|null // (новое)
│   ├── discount: float
│   ├── promocode: Promocode|null  // {id, code, percent}
│   ├── bonus: object|null         // (новое)
│   ├── weightTotal: float|null    // (новое) кг, может быть null
│   ├── isHeavy: bool              // (новое)
│   └── availableFrom: string|null // (новое) ISO 8601, может быть null
├── weightTotal: float|null        // (новое)
├── isHeavy: bool                  // (новое)
└── availableShippingMethods: []   // (новое)
```

Позиция корзины (`CartItem` в ответе):

```
CartItem
├── id: int
├── product: Product
├── quantity: int
├── price: float                    // с копейками
├── priceOld: float|null            // (новое) зачёркнутая цена, снапшот
├── discountPercent: int|null       // (новое) из снапшота price_old → price
├── quantityInStock: int|null       // может быть null
├── stockWarning: string|null
├── supplements: Supplement[]       // виртуальные + свёрнутые товарные добавки
├── supplementsTotal: int
└── deliveryDate: string|null       // (новое) присутствует только в marketplace-режиме
```

Дочерние позиции (товарные добавки, `parent_id != null`) отдельными элементами `items` не выводятся: они свёрнуты в `supplements[]` родительской позиции. Детали контракта добавок — в `20_product_supplements.md`.

Блок `bonus` группы (присутствует только если у магазина включены бонусы):

```
bonus
├── isEnabled: bool        // всегда true, когда блок есть
├── eligibleAmount: int
├── earnPercent: int
├── spendPercent: int
├── maxEarn: int
├── maxSpend: int
└── userBalance: int
```

Блок `availableShippingMethods` — список из четырёх элементов в фиксированном порядке:

```json
[
  {"method": "own_courier",  "label": "Доставка курьером",     "available": true, "reason": null},
  {"method": "pickup",       "label": "Самовывоз из магазина", "available": true, "reason": null},
  {"method": "cdek",         "label": "ПВЗ СДЭК",              "available": false, "reason": "Модуль выключен"},
  {"method": "russian_post", "label": "ОПС Почты России",      "available": false, "reason": "Модуль выключен"}
]
```

Правила доступности:
- `pickup` — всегда `available: true`, `reason: null`.
- `cdek` / `russian_post` — доступны, если включён соответствующий модуль **и** у каждого магазина корзины активна интеграция (`slots-api`, конфиги `delivery_cdek` / `delivery_russian_post`). Причины: `"Модуль выключен"`, `"Часть товаров корзины недоступна для СДЭК"`, `"Часть товаров корзины недоступна для Почты России"` — конкретные `store_id` не раскрываются.
- Если хотя бы у одного товара корзины `pickup_only = true`, у `own_courier`, `cdek`, `russian_post` выставляется `available: false` и `reason: "В заказе есть товар, доступный только для самовывоза."`.

### 3.6 Структура ответа API — marketplace-режим (новое)

`ClientCartResource`, **без обёртки `data`**:

```
Cart
├── dateGroups: DateGroup[]
│   ├── date: string|null          // Y-m-d, null = позиции без выбранной даты
│   ├── items: CartItem[]
│   ├── subtotal: float
│   ├── itemsCount: int
│   ├── weightTotal: float|null
│   └── availableFrom: string|null // ISO 8601
├── totalAmount: float             // сумма позиций за вычетом скидки промокода
├── totalAmountBeforeDiscount: float
├── totalDiscount: float
├── totalShippingPrice: float
├── totalItemsCount: int
├── promotionDiscount: float
├── vatAmount: float
├── totalToPay: float
├── bonus: object|null
├── freeShippingFrom: int|null
├── isMultiDate: bool
├── estimatedDates: (string|null)[]
├── promocode: object|null
├── weightTotal: float|null
├── isHeavy: bool
└── availableShippingMethods: []
```

Группировка `dateGroups` идёт по `cart_items.delivery_date` **по всем позициям, включая дочерние** (товарные добавки попадают в группу отдельными элементами и учитываются в `itemsCount`). Группы отсортированы по ключу даты, группа без даты (`date: null`) идёт первой. Если у корзины нет позиций, возвращается одна группа с `date: null` и пустым `items`.

Блок `promocode` (platform-wide промокод, `cart_promocodes.store_id = null`):

```
promocode
├── code: string
├── type: string                 // "percent" | "fixed"
├── value: float
├── discount: float
├── appliedToItemsCount: int
├── totalItemsCount: int
└── isPartialApplication: bool
```

Блок `bonus` в marketplace-корзине агрегирован по всей корзине (присутствует, если хотя бы у одного магазина корзины включены бонусы):

```
bonus
├── isEnabled: bool
├── eligibleAmount: int
├── maxEarn: int
├── maxSpend: int
└── userBalance: int    // доступный остаток = bonus_balance − bonus_reserved
```

## 4. Логика

### 4.1 getCart [AUTH]

**Endpoint:** `GET /api/v1/cart`

**Query:**
- `addressId` — optional, int. Если передан — цены доставки рассчитываются для этого адреса. Если не передан — берётся активный адрес пользователя. Адрес без координат (`latitude`/`longitude`) приравнивается к отсутствию адреса.
- `shippingMethod` — optional, string, дефолт `courier`. Значение не валидируется; распознаются `pickup` (стандартный режим) и `pickup`, `cdek`, `russian_post` (marketplace).

**Логика сервера:**
1. Резолвится адрес (`addressId` либо активный адрес пользователя).
2. Загружается корзина пользователя. Если записи корзины нет — возвращается пустой ответ (см. 4.1.1).
3. Синхронизируются цены позиций: `price` ← актуальная цена товара с учётом акций, `price_old` ← оригинальная цена до скидки (`null`, если скидки нет). Снятая скидка исчезает из позиции, назначенная — появляется.
4. Удаляются позиции с недоступными товарами: товара нет, товар soft-deleted, `is_active = false`, либо `quantity <= 0` (нулевой остаток не делает товар недоступным, если магазин/товар работает в режиме «не учитывать остатки»).
5. Возвращается `CartResource` (стандартный режим) или `ClientCartResource` (marketplace).

Синхронизация цен и удаление недоступных позиций выполняются **только** в этой ручке; остальные ручки корзины возвращают позиции как есть.

**Ответ 200 (стандартный режим):**
```json
{
  "storeGroups": [
    {
      "id": 1,
      "store": {
        "id": 1,
        "name": "Магазин №1",
        "deliveryInfo": {"deliveryPrice": 200, "minOrderAmount": 500, "freeDeliveryFrom": 3000}
      },
      "items": [
        {
          "id": 1,
          "product": {"id": 10, "sku": "MILK-32", "title": "Молоко 3.2%"},
          "quantity": 2,
          "price": 89.5,
          "priceOld": 99.0,
          "discountPercent": 10,
          "quantityInStock": 12,
          "stockWarning": null,
          "supplements": [],
          "supplementsTotal": 0
        }
      ],
      "deliveryCost": 200.0,
      "assemblyCost": 0,
      "freeDeliveryFrom": 3000,
      "discount": 50.0,
      "promocode": {"id": 1, "code": "PROMO10", "percent": 10},
      "bonus": null,
      "weightTotal": 2.0,
      "isHeavy": false,
      "availableFrom": null
    }
  ],
  "weightTotal": 2.0,
  "isHeavy": false,
  "availableShippingMethods": [...]
}
```

**Ошибки:**
- 404 — `{"error": "address_not_found", "message": "Адрес не найден"}`, если `addressId` не существует
- 403 — `{"error": "forbidden", "message": "Доступ запрещён"}`, если адрес принадлежит другому пользователю

#### 4.1.1 Пустой ответ (записи корзины нет)

Стандартный режим:
```json
{"storeGroups": [], "weightTotal": null, "isHeavy": false}
```

Marketplace-режим:
```json
{
  "dateGroups": [],
  "totalAmount": 0.0,
  "totalAmountBeforeDiscount": 0.0,
  "totalDiscount": 0.0,
  "totalShippingPrice": 0.0,
  "totalItemsCount": 0,
  "promotionDiscount": 0.0,
  "vatAmount": 0.0,
  "totalToPay": 0.0,
  "freeShippingFrom": 30000.0,
  "isMultiDate": false,
  "estimatedDates": [],
  "promocode": null,
  "weightTotal": null,
  "isHeavy": false,
  "bonus": null
}
```

Поле `availableShippingMethods` в пустом ответе отсутствует. `freeShippingFrom` берётся из настройки `marketplace_free_shipping_threshold` как дробное число (в наполненной корзине то же значение приводится к int), **может быть null**. Тот же пустой ответ отдают `DELETE /cart/promocode` и `DELETE /cart/stores/{store}`, когда у пользователя нет записи корзины.

Если запись корзины есть, но позиций в ней нет, ответ формирует ресурс: в стандартном режиме `storeGroups: []` с `availableShippingMethods`, в marketplace — одна группа `dateGroups` с `date: null` и пустым `items`.

### 4.2 addToCart [AUTH]

**Endpoint:** `POST /api/v1/cart/items`

**Входные данные:**
```json
{
  "productId": 10,
  "quantity": 2,
  "supplements": [
    {"supplementId": 5, "valueIds": [12, 13]}
  ],
  "deliveryDate": "2026-04-20"
}
```

**Валидация:**
- `productId` — required, integer, `exists:products,id` с условием `is_configurable = 0` (вариативный родитель не добавляется, нужен конкретный вариант)
- `quantity` — required, integer, min 1, **max 99999**
- `supplements` — sometimes, array + правило `CartSupplementsRule`. Элемент: `{supplementId: int, valueIds: int[]}` либо `{supplementId: int, valueQuantities: [{valueId: int, quantity: int}]}`. Ключ `values` принимается как устаревший алиас `valueIds`
- `supplements.*.supplementId` — required, integer, `exists:supplements,id`
- `supplements.*.valueIds` — sometimes, array, min 1; `valueIds.*` — integer, `exists:supplement_values,id`
- `supplements.*.valueQuantities` — sometimes, array, min 1; `valueQuantities.*.valueId` — required, integer, exists; `valueQuantities.*.quantity` — required, integer, min 1
- `deliveryDate` — nullable, `Y-m-d`, `after_or_equal:today`. Сохраняется в позицию в любом режиме, в ответе появляется только в marketplace-режиме

**Логика сервера:**
1. Товар загружается с фильтром `is_active = true`; отсутствующий или неактивный товар — 404.
2. При включённом модуле `b2b`: B2C-клиент не может добавлять товары B2B-магазина.
3. Количество проверяется на кратность `products.quantity_step`.
4. Если `supplements` не переданы, а у товара есть обязательные добавки — запрос отклоняется.
5. Корзина создаётся при первом добавлении; конкурентное создание обрабатывается повтором транзакции (до 3 попыток), на контракт не влияет.
6. Ищется существующая корневая позиция с тем же `product_id` **и идентичным набором добавок** (виртуальных и товарных). Найдена — `quantity` суммируется, цены и `price_old` обновляются, количества дочерних позиций пересчитываются пропорционально порциям; не найдена — создаётся новая позиция (и дочерние позиции для товарных добавок).
7. Остатки проверяются и для основного товара (суммарное количество после мержа), и для товарных добавок, отслеживающих остаток.

**Ответ 200:** `CartResource` / `ClientCartResource` (см. 3.5, 3.6). Адрес для расчёта доставки берётся из активного адреса пользователя с координатами.

**Ошибки:**
- 404 — товар не найден, неактивен или удалён
- 422 — товар вариативный: `"Товар не найден или является вариативным (выберите конкретный вариант)"`
- 422 — `"Количество должно быть кратно {step}"`
- 422 — `"Не все обязательные добавки выбраны"`
- 422 — недостаточно на складе: `"Товар '{название}' недоступен в нужном количестве. Доступно: {N}, запрошено: {M}"` (ключ ошибки `quantity`); та же ошибка возникает при нехватке остатка товарной добавки
- 403 — `{"message": "Магазин недоступен", "errors": {"storeId": [...]}}` при B2B-ограничении

### 4.2а addItemsBatch [AUTH]

**Endpoint:** `POST /api/v1/cart/items/batch`

Массовое добавление товаров одним запросом. Каждый товар добавляется той же логикой, что и в `POST /cart/items` (мерж позиций, проверки остатка, кратности шага), но без добавок и без `deliveryDate`.

**Валидация:**
- `items` — required, array, min 1, **max 100**
- `items.*.productId` — required, integer (существование не проверяется валидатором — несуществующий товар попадает в `skippedProducts`)
- `items.*.quantity` — required, integer, min 1, max 99999

**Response 200:**
```json
{
  "added": 3,
  "skipped": 2,
  "skippedProducts": [
    {"id": 5, "title": "Молоко 3.2%", "reason": "out_of_stock"},
    {"id": 7, "title": "", "reason": "not_found"}
  ]
}
```

Значения `reason`: `not_found` (товара нет в БД), `out_of_stock` (нехватка остатка), `store_access_denied` (B2B-ограничение), `unavailable` (любая другая ошибка добавления, включая неактивный товар, вариативность и некратное количество). Для `not_found` поле `title` пустая строка.

**Response 422:** тот же shape, если `added == 0` и `skipped > 0`.

### 4.3 updateQuantity [AUTH]

**Endpoint:** `PUT /api/v1/cart/items/{cartItem}`

**Входные данные:**
```json
{
  "quantity": 3,
  "supplements": []
}
```

**Валидация:**
- `quantity` — required, integer, min 1, **max 99999**
- `supplements` — sometimes, array + `CartSupplementsRule`. `supplements.*.supplementId` — required, integer, exists; `supplements.*.valueIds` — **required**, array, min 1; `valueIds.*` — integer, exists. Ключ `values` принимается как алиас `valueIds`
- `deliveryDate` — nullable, `Y-m-d`, `after_or_equal:today`

**Логика сервера:**
1. Проверяется владелец позиции (политика `CartItemPolicy`).
2. Позиция блокируется на запись, количество проверяется на кратность шага и на остаток товара.
3. `price` и `price_old` перезаписываются актуальными значениями товара.
4. `delivery_date` обновляется только при непустом `deliveryDate`; отсутствие поля или `null` существующую дату не затирает.
5. Если `supplements` переданы (в том числе пустым массивом) — виртуальные и дочерние позиции удаляются и создаются заново по переданному набору. Пустой массив снимает все добавки. Если `supplements` не переданы — количества дочерних позиций пересчитываются пропорционально изменению порций, с проверкой остатка добавок, отслеживающих склад.

**Ответ 200:** `CartResource` / `ClientCartResource`.

**Ошибки:**
- 404 — элемент корзины не найден
- 403 — чужая корзина
- 422 — `"Количество должно быть кратно {step}"`
- 422 — недостаточно товара или товарной добавки на складе

### 4.4 removeItem [AUTH]

**Endpoint:** `DELETE /api/v1/cart/items/{cartItem}`

**Логика сервера:**
1. Проверяется владелец позиции (политика `CartItemPolicy`).
2. Позиция удаляется; дочерние позиции (товарные добавки) удаляются каскадом.
3. Если позиций этого магазина в корзине не осталось — удаляется store-scoped промокод этого магазина. Platform-wide промокод не затрагивается.
4. Возвращается обновлённая корзина.

**Ошибки:** 404 — позиция не найдена; 403 — чужая позиция.

### 4.5 clearCart [AUTH]

**Endpoint:** `DELETE /api/v1/cart`

**Логика сервера:**
1. Удаляются все позиции корзины и все записи промокодов (store-scoped и platform-wide).
2. Отсутствие корзины ошибкой не считается.
3. Возвращается **204 No Content** (пустое тело).

### 4.5а deliverySummary [AUTH] · **marketplace only**

**Endpoint:** `GET /api/v1/cart/delivery-summary`

Агрегированная сводка доставки по всей корзине. Маршрут закрыт middleware `marketplace.mode` — в стандартном режиме отдаёт **404**.

**Query:** `addressId` — optional, int. Если не передан, берётся активный адрес пользователя с координатами.

**Логика сервера:**
1. Позиции группируются по магазинам; для каждого магазина запрашивается доставка на **завтрашнюю** дату. Магазины, по которым внешний сервис недоступен, пропускаются (graceful degradation).
2. `totalShippingPrice` — сумма цен доставки по магазинам; если сумма позиций (`price × portions`) достигает marketplace-порога бесплатной доставки, обнуляется.
3. `estimatedDates` — уникальные даты начала первого слота каждого магазина, отсортированные по возрастанию.
4. `dateGroups` в этой ручке всегда пустой массив.

**Response 200:**
```json
{
  "totalShippingPrice": 600.0,
  "freeShippingFrom": 30000,
  "estimatedDates": ["2026-04-16", "2026-04-17"],
  "isMultiDate": true,
  "dateGroups": [],
  "availableShippingMethods": [...]
}
```

`freeShippingFrom` — порог из настроек магазина, **может быть null**, если не сконфигурирован.

**Ошибки:** 404 — `{"error": "address_not_found"}`, если переданный `addressId` не существует или принадлежит другому пользователю (поле `message` в этом ответе отсутствует).

### 4.6 applyPromocode [AUTH]

**Endpoint:** `POST /api/v1/cart/promocode`

**Входные данные:**
```json
{
  "storeId": 1,
  "promocode": "PROMO10"
}
```

**Валидация:**
- `storeId` — **nullable в marketplace-режиме**, required в стандартном; integer, `exists:stores,id`
- `promocode` — required, string, max 50

**Выбор ветки:** в marketplace-режиме промокод ищется по коду, и если у него `scope = 'platform'`, он применяется ко всей корзине (запись с `store_id = null`). Во всех остальных случаях работает store-scoped ветка; при `storeId = null` она отвечает ошибкой.

**Логика store-scoped ветки:**
1. Проверяется наличие корзины и наличие в ней позиций этого магазина.
2. Промокод ищется по `code`.
3. Проверяются лимиты использования: общий (`max_uses` против записей в `promocode_usages`) и на клиента (`max_uses_per_client` против записей этого клиента); записи по отменённым заказам не считаются.
4. Проверяется валидность: активен, текущая дата в интервале `starts_at`–`ends_at`, общий лимит не исчерпан.
5. Проверяется соответствие магазину: подходят промокоды с `store_id = null` или совпадающим `store_id`.
6. Проверяется, что промокод даёт скидку: среди позиций магазина есть товары, подходящие под привязку, и среди них есть хотя бы один без скидки.
7. Проверяется `min_order_amount` — против суммы `price × portions` по всем позициям магазина.
8. Промокод сохраняется в `cart_promocodes` для этого магазина (перезаписывая предыдущий).

**Логика platform-wide ветки:** те же проверки, но по всем позициям корзины без учёта магазина; `min_order_amount` сравнивается с суммой только по eligible-товарам (без скидки и подходящим под привязку). Промокод сохраняется одной записью с `store_id = null`.

**Ответ 200:** `CartResource` / `ClientCartResource`. В стандартном режиме platform-wide запись в `storeGroups` не отображается и скидку не даёт — она видна только в marketplace-ответе.

**Ошибки (все 422, тело `{"message": ..., "errors": {"promocode": [...]}}`):**
- `"Корзина пуста."`
- `"В корзине нет товаров из этого магазина."` / `"В корзине нет товаров."`
- `"Промокод не найден."`
- `"Промокод больше недоступен"` — исчерпан `max_uses`
- `"Вы уже использовали этот промокод"` — исчерпан `max_uses_per_client`
- `"Промокод недействителен или истёк."`
- `"Промокод не подходит для этого магазина."`
- `"Промокод не распространяется на товары в корзине."` — ни один товар не подходит под привязку
- `"Промокод нельзя применить: все товары в корзине уже участвуют в акции."`
- `"Минимальная сумма заказа для промокода: {N} руб."`
- `"Для store-scoped промокода необходимо указать storeId."` — `storeId` не передан, а промокод не platform-wide

Ошибки валидации формы: `"Укажите промокод"`, `"Промокод слишком длинный"`, `"Магазин не найден"`.

### 4.7 removePromocode [AUTH]

**Endpoint:** `DELETE /api/v1/cart/promocode`

**Входные данные:**
```json
{
  "storeId": 1
}
```

- `storeId` — **nullable**, integer, `exists:stores,id`. `null` (или отсутствие поля) удаляет platform-wide промокод (`store_id IS NULL`), значение — промокод конкретного магазина.

**Логика сервера:**
1. Удаляется соответствующая запись `cart_promocodes` (отсутствие записи ошибкой не считается).
2. Возвращается обновлённая корзина; при отсутствии записи корзины — пустой ответ (см. 4.1.1).

### 4.8 clearStoreGroup [AUTH]

**Endpoint:** `DELETE /api/v1/cart/stores/{store}`

**Логика сервера:**
1. Удаляются все позиции указанного магазина (дочерние позиции — каскадом).
2. Удаляется промокод этого магазина.
3. Возвращается обновлённая корзина; при отсутствии записи корзины — пустой ответ.

**Ошибки:** 404 — магазин не существует (route model binding).

### 4.9 switchStore [AUTH] (новое, MID-204)

**Endpoint:** `POST /api/v1/cart/store`

Вызывается клиентом после смены адреса доставки или магазина самовывоза. Контракт для клиентов — `2026-08-26-cart-store-switch-api-contract.md`.

**Body:** `storeId` (int, необязательный; `nullable|integer`, существующий активный магазин). Без него магазин резолвится как при добавлении в корзину — первый доступный по активному адресу (`CartStoreOffers::resolvePurchaseStoreId`).

**Логика сервера (сетевой каталог, `SwitchCartStoreAction`):**
1. Корневые позиции берутся под `lockForUpdate`, позиции целевого магазина обрабатываются первыми (в них сливаются одинаковые позиции других магазинов).
2. Для каждой позиции резолвятся офферы целевого магазина: карточки и всех дочерних позиций (товарных добавок). Недоступна (карточка неактивна/удалена, нет оффера, остаток 0 при учёте остатков, у добавки нет оффера) — позиция удаляется (дочерние каскадом) и попадает в `removedItems` `{productId, title}`.
3. Позиция другого магазина с доступными офферами: если в целевом магазине уже есть позиция с тем же ключом «товар + набор добавок» (`HandlesSupplements::buildSupplementsKeyFromCartItem`) — количества суммируются, дочерние позиции целевой пересчитываются по порциям; иначе позиция перепривязывается (`store_id`, `price`/`price_old` из оффера целевого магазина, дочерние — из офферов добавок, зеркально `createCatalogChildren`).
4. Позиции, уже лежащие в целевом магазине, не трогаются (цену пересинхронизирует `GET /cart`).
5. Store-scoped промокоды магазинов, в которых не осталось позиций, удаляются; platform-wide (`store_id = null`) не затрагивается.
6. Всё в одной транзакции (`attempts: 3`).

**Ответ 200:** корзина как в `GET /cart` (`CartResource`/`ClientCartResource` через `resolve()`, без обёртки `data`) + `storeId`, `removedItems[]`, `hasUnavailableItems` (= `removedItems` не пуст). Без записи корзины — пустая форма (4.1.1) + те же три поля.

**Легаси-режим (флаг `network_catalog` выключен):** корзина не меняется, `removedItems: []`.

**Ошибки:** 422 `errors.storeId` — магазин не определился (`Не удалось определить магазин: передайте storeId или выберите адрес`) либо не найден/неактивен (`Магазин не найден`).

## 5. Расчёты

### 5.1 Скидка по промокоду

Промокод применяется только к eligible-товарам: без скидки (`price_old` пуст **и** нет активной акции) и подходящим под привязку `binding_type`.

```
eligibleSubtotal = round(Σ price × portions по eligible-позициям, 2)
eligibleSubtotal <= 0            → 0
type = percent                   → round(eligibleSubtotal × value / 100, 2)
type = fixed                     → round(min(value, eligibleSubtotal), 2)
```

Добавки (виртуальные и товарные) в базу промокода не входят: в стандартном режиме скидка считается по корневым позициям магазина.

`promocode.percent` в группе заполняется только для типа `percent`; для `fixed` — `null`.

### 5.2 Стоимость доставки группы (стандартный режим)

Расчёт зависит от `shippingMethod` и наличия адреса с координатами:

- `shippingMethod=pickup`: `deliveryCost = 0`, `minOrderAmount = stores.min_pickup_order_amount ?? stores.min_order_amount ?? 0`, `freeDeliveryFrom = null`.
- Иначе, при наличии адреса, запрашивается доставка на **сегодняшнюю** дату:
  - если внешняя система вернула `delivery_type = yandex` — показывается реальная цена Яндекс-доставки (`checkPrice`), та же, что применяется при создании заказа; при недостижимости Яндекса используется цена зоны. `minOrderAmount` и `freeDeliveryFrom` берутся из ответа внешней системы;
  - иначе цена, минимальная сумма и порог бесплатной доставки берутся из зонных данных `slots-api`, а при их отсутствии — из полей магазина (`delivery_price`, `min_order_amount`, `free_delivery_from`).
- Порог бесплатной доставки: если сумма группы **с добавками** достигает `freeDeliveryFrom`, доставка равна 0.
- Ошибка обращения к внешней системе не ломает ответ: расчёт продолжается по данным магазина.

Сумма с добавками:
```
subtotal = Σ ( (price + Σ цен виртуальных добавок) × portions + Σ (price × portions) дочерних позиций )
```

### 5.3 Стоимость сборки (assemblyCost)

При выключенном модуле `assembly_cost` поле всегда `0`. При включённом — зонная стоимость сборки из `slots-api` (доступна для курьерской и Яндекс-доставки при наличии адреса), при её отсутствии — `stores.assembly_price`, иначе `0`.

### 5.4 Вес и признак тяжёлого заказа

`weightTotal` — килограммы, округление до 3 знаков; **может быть null**, если ни одна позиция не дала вес.

- В группе — по позициям группы (корневым).
- В корне стандартного ответа — по корневым позициям всей корзины.
- В marketplace-ответе — по всем позициям корзины, включая дочерние; в группе даты — по позициям этой группы.

`isHeavy` активен только при включённом модуле `heavy_orders` **или** `courier_assignment`. Группа считается тяжёлой, если суммарный вес её корневых позиций строго превышает `stores.heavy_order_threshold` (граммы). В корне ответа `isHeavy = true`, если тяжёлой оказалась хотя бы одна группа магазина.

### 5.5 Готовность (availableFrom)

`availableFrom` — максимальный момент готовности товаров, ISO 8601, **может быть null**. При выключенном модуле `product_ready_time` всегда `null`. В стандартном режиме считается по **полному** множеству позиций магазина, включая дочерние добавки, — чтобы значение совпадало с валидацией слота при создании заказа.

### 5.6 Бонусы

Стандартный режим, блок на группу (только при `stores.is_bonus_enabled`):
```
eligibleAmount = 0, если к группе применён промокод;
                 иначе Σ price × portions по позициям без скидки
maxEarn        = floor(eligibleAmount × bonus_earn_percent / 100)
maxSpend       = min(floor(eligibleAmount × bonus_spend_percent / 100), userBalance)
userBalance    = users.bonus_balance
```

Marketplace, агрегированный блок по корзине (только если хотя бы у одного магазина включены бонусы):
```
eligibleAmount = Σ по магазинам с включёнными бонусами (позиции без скидки),
                 0 — если применён платформенный промокод
maxEarn        = Σ floor(eligible магазина × bonus_earn_percent / 100)
maxSpend       = min(Σ квот по магазинам, userBalance)
userBalance    = доступный остаток = bonus_balance − bonus_reserved (не меньше 0)
```

Бонусы и промокод взаимоисключающи: применённый промокод обнуляет квоты.

### 5.7 Итоги marketplace-корзины

```
subtotal группы           = Σ price × portions по позициям группы
totalAmount               = max(0, Σ subtotal групп − скидка платформенного промокода)
promotionDiscount         = Σ (price_old − price) × portions по позициям, где price_old > price
totalAmountBeforeDiscount = Σ subtotal групп + promotionDiscount
totalDiscount             = promotionDiscount + скидка платформенного промокода
vatAmount                 = Σ НДС, включённого в цену позиции
totalToPay                = totalAmount + totalShippingPrice
```

- Цены позиций уже акционные (синхронизация в `GET /cart`), поэтому `promotionDiscount` из `totalAmount` не вычитается — он участвует только в `totalAmountBeforeDiscount` и `totalDiscount`.
- `vatAmount` считается по ставке из `products.vat_rate` (код ставки ЮKassa; при отсутствии — код из конфигурации, по умолчанию `4` = 20%). НДС включён в цену: `gross × rate / (100 + rate)`. Промокод и бонусы эту строку не корректируют — точный расчёт выполняется при фискализации чека.
- `totalItemsCount` — количество корневых позиций (дочерние добавки не считаются). При применённом платформенном промокоде это поле пересчитывается по всем позициям корзины, включая дочерние, и совпадает с `promocode.totalItemsCount`.
- `totalShippingPrice` — сумма по магазинам, рассчитанная так же, как `deliveryCost` группы (включая Яндекс-доставку). Обнуляется в двух случаях: сумма корзины достигла marketplace-порога `freeShippingFrom`, либо `shippingMethod` ∈ `pickup`, `cdek`, `russian_post`. Для `cdek` / `russian_post` тариф ПВЗ в корзине ещё неизвестен — он определяется выбранным пунктом выдачи при создании заказа.
- `isMultiDate` — `true`, если непустых дат больше одной; группа без даты (`null`) в подсчёте не участвует.
- `estimatedDates` — даты групп в порядке групп, **может содержать null**.

### 5.8 Синхронизация цен и снапшот скидки

`GET /cart` приводит `price` и `price_old` позиции к текущему состоянию товара:
- `price` — цена с учётом большей из скидок (ручной `price_old` или активной акции);
- `price_old` — оригинальная цена до скидки, `null`, если итоговая цена не ниже базовой.

`discountPercent` позиции считается из снапшота: `round(100 − price / price_old × 100)`, `null` при отсутствии `price_old` или неположительном проценте.

### 5.9 Предупреждения об остатке

- `quantityInStock` — остаток товара; **null**, если товар/магазин работает в режиме «не учитывать остатки» или остаток равен нулю.
- `stockWarning` — `"Доступно только {N} {ед}"`, если количество в позиции превышает остаток; `"Осталось только {N} {ед}"`, если остатка хватает не более чем на 5 шагов покупки; иначе `null`. `{N}` кратно `quantity_step`, `{ед}` — единица измерения товара (по умолчанию `шт`).

## 6. Нефункциональные требования

### Бизнес-правила
- Максимум 99999 единиц одного товара в позиции
- Максимум 100 позиций в одном batch-запросе; ограничения на общее число позиций корзины нет
- Один промокод на магазин и один platform-wide промокод на корзину
- Позиции с недоступными товарами удаляются при получении корзины
- Одна карточка товара может присутствовать в корзине несколько раз — с разными наборами добавок; одинаковые наборы мержатся в одну позицию

### Транзакции
- `addToCart` — атомарная операция; повтор транзакции при deadlock и при гонке создания корзины (до 3 попыток)
- `updateItem` — атомарная операция с блокировкой строки позиции; повтор при deadlock
- Удаление позиции, очистка корзины и группы магазина выполняются без явной транзакции

## 7. Реализация

### Код
- Миграции: `create_carts_table`, `create_cart_items_table`, `create_promocodes_table`, `create_cart_promocodes_table`, `drop_cart_items_unique_product_index`, `add_delivery_date_to_cart_items`, `make_store_id_nullable_cart_promocodes`, `add_price_old_to_cart_items_table`, `change_money_columns_to_decimal`, `change_price_old_columns_to_decimal`, `add_parent_to_cart_items_table`, `add_scope_to_promocodes_table`, `add_binding_to_promocodes`, `add_max_uses_per_client_to_promocodes`, `create_promocode_usages_table`
- Модели: `Cart`, `CartItem`, `CartItemSupplement`, `CartPromocode`, `Promocode`, `PromocodeUsage`
- `CartController`
- Ресурсы: `CartResource`, `CartStoreGroupResource`, `CartItemResource`, `CartItemSupplementResource`, `ClientCartResource`, `PromocodeResource`
- Actions: `AddToCartAction`, `BatchAddToCartAction`, `UpdateCartItemAction`, `RemoveCartItemAction`, `ClearCartAction`, `ClearStoreGroupAction`, `SwitchCartStoreAction`, `ApplyPromocodeAction`, `RemovePromocodeAction`, трейт `Concerns\HandlesSupplements`
- Сервисы: `CartCalculator`, `AvailableShippingMethodsResolver`, `HeavyOrderResolver`, `WeightAggregator`, `ReadyTimeResolver`, `Vat`
- Requests: `AddToCartRequest`, `BatchAddToCartRequest`, `UpdateCartItemRequest`, `ApplyPromocodeRequest`, `RemovePromocodeRequest`, `SwitchCartStoreRequest`, правило `CartSupplementsRule`
- Политика: `CartItemPolicy` (update, delete)

### Тесты
- `tests/Feature/Cart/BatchAddToCartTest`
- `tests/Feature/Cart/ApplyPromocodeEligibleItemsTest`
- `tests/Feature/Cart/ApplyPromocodeUsageLimitsTest`
- `tests/Feature/Cart/CartCalculatorBindingTest`
- `tests/Feature/Cart/CartWeightTotalTest`
- `tests/Feature/Cart/CatalogSupplementTest`
- `tests/Feature/Cart/AvailableShippingMethodsPickupOnlyTest`
- `tests/Feature/Cart/CartProductsMapperTest`
- `tests/Feature/CartHeavyFlagTest`
- `tests/Feature/CartReadyTimeTest`
- `tests/Feature/NetworkCatalogCartStoreSwitchTest`

### Routes
```php
Route::middleware('auth:sanctum')->prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'index']);
    Route::delete('/', [CartController::class, 'clear']);

    Route::post('/items', [CartController::class, 'addItem']);
    Route::post('/items/batch', [CartController::class, 'batchAddItems']);
    Route::put('/items/{cartItem}', [CartController::class, 'updateItem']);
    Route::delete('/items/{cartItem}', [CartController::class, 'removeItem']);

    Route::post('/promocode', [CartController::class, 'applyPromocode']);
    Route::delete('/promocode', [CartController::class, 'removePromocode']);

    Route::delete('/stores/{store}', [CartController::class, 'clearStoreGroup']);

    Route::post('/store', [CartController::class, 'switchStore']);
});

// Marketplace only — отдельный маршрут вне группы
Route::middleware(['auth:sanctum', 'marketplace.mode'])
    ->get('cart/delivery-summary', [CartController::class, 'deliverySummary']);
```

---

## 8. Дополнение заказа — ⚠️ эндпоинт `POST /orders/{id}/append` больше не существует

Ранее описанный в ТЗ механизм «append» (один HTTP endpoint, проверка `canAppendToOrder` возвращает объект `appendableOrder` в корзине) — **не реализован** в текущем коде.

Вместо него используется другой механизм — **supplement**:

- `GET /api/v1/orders/check-supplement?store_id=1` — вернёт `{"order_id": 123, "can_supplement": true}`, если у пользователя есть заказ, который можно дополнить.
- `POST /api/v1/orders/{order}/supplement` — добавляет товары корзины в существующий заказ.

Детали — в `06_orders.md` и `order_supplement_spec.md`.
