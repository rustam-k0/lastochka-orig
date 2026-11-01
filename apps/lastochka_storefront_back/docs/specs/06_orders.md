# ТЗ: Заказы

## 1. Цель

Создание заказов из корзины и управление их жизненным циклом.

## 2. Контекст

- **Модуль:** Order
- **Триггер:** Пользователь оформляет заказ из корзины
- **Особенность:** При создании заказа делается snapshot всех данных (адрес, товары, цены, промокод, бонусы, ставка НДС)
- **Оплата:** `cash` (при получении) и `card` (онлайн через YooKassa — холд при создании, списание при сборке)
- **Способы доставки:** `courier`, `pickup` (самовывоз из магазина), `cdek`, `russian_post` (ПВЗ сторонних служб). Значение `yandex_delivery` проставляет сервер сам, клиент его не передаёт.
- **Деньги:** `decimal(12,2)` в БД, float с копейками в API. Бонусы — целые рубли (int).

Поведение части полей зависит от фича-флагов (админка → Настройки → Модули): `heavy_orders`, `assembly_cost`, `courier_assignment`, `marketplace_mode`, `b2b`, `cdek`, `russian_post`, `collector_payment`, `address_objects`.

## 3. Данные

### 3.1 Модель Order

```
orders
├── id: bigint PK
├── client_order_id: uuid NULL INDEX          // marketplace: связка sub-order'ов (новое)
├── user_id: bigint FK → users.id
├── store_id: bigint FK → stores.id
├── external_id / external_source: string NULL
├── source: string(50) DEFAULT 'storefront'
├── status: string(30) NOT NULL               // OrderStatus, см. ниже
├── payment_type: string(20) NOT NULL         // cash | card
├── is_paid: boolean DEFAULT false
├── held_amount: decimal(10,2) NULL           // сумма холда YooKassa
├── captured_amount: decimal(10,2) NULL       // фактически списано
├── refunded_amount: decimal(10,2) NULL       // возвращено
├── surcharge_amount: decimal(10,2) NULL      // требуемая доплата (новое)
├── surcharge_expires_at: datetime NULL       // дедлайн доплаты (новое)
├── payment_card_id: bigint FK NULL → payment_cards.id
├── subtotal: decimal(12,2) NOT NULL          // Сумма товаров без скидки
├── discount: decimal(12,2) DEFAULT 0         // Скидка по промокоду
├── eligible_amount: decimal(12,2) DEFAULT 0  // База начисления бонусов
├── delivery_cost: decimal(12,2) DEFAULT 0    // Стоимость доставки
├── assembly_cost: int unsigned DEFAULT 0     // Стоимость сборки (новое)
├── total: decimal(12,2) NOT NULL             // Итого к оплате
├── bonus_earned: int unsigned DEFAULT 0
├── bonus_spent: int unsigned DEFAULT 0
├── items_count: int NOT NULL                 // Только корневые позиции
├── is_heavy: boolean DEFAULT false           // Тяжёлый заказ (новое)
├── couriers_count: tinyint unsigned DEFAULT 0 // Курьеров на доставке (новое)
├── delivery_slot_start: datetime NULL
├── delivery_slot_end: datetime NULL
├── delivery_slot_display: string(50) NULL    // "10:00-12:00"
├── shipping_method: string(20) DEFAULT 'courier'
├── shipping_tariff_code: string(64) NULL     // тариф СДЭК/Почты (новое)
├── pickup_external_id: string(64) NULL       // код ПВЗ (новое)
├── pickup_address_data: json NULL            // снапшот ПВЗ (новое)
├── cdek_number / cdek_status: string(64) NULL
├── rp_barcode / rp_status: string(64) NULL
├── pickup_timer_expires_at: datetime NULL    // автоотмена самовывоза
├── tracking_number: string(100) NULL
├── comment: text NULL
├── idempotency_key: string(64) NULL          // UNIQUE(user_id, idempotency_key)
├── analytics_session_id: string(64) NULL     // из заголовка запроса (новое)
├── analytics_device_client_id: string(64) NULL // из заголовка запроса (новое)
├── client_order_finalized_at: datetime NULL  // marketplace-финализация
├── created_at / updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Статусы (OrderStatus enum):**
```php
enum OrderStatus: string
{
    case Draft = 'draft';               // Черновик: оформлен, оплата не подтверждена
    case Pending = 'pending';           // Создан, ожидает обработки
    case Assembling = 'assembling';     // Собирается
    case Assembled = 'assembled';       // Собран
    case InDelivery = 'inDelivery';     // В доставке
    case Delivered = 'delivered';       // Доставлен
    case Cancelled = 'cancelled';       // Отменён
}
```

> В API статус отдаётся значением enum: `pending`, `assembling`, `assembled`, **`inDelivery`** (camelCase), `delivered`, `cancelled`. В событиях RabbitMQ тот же статус транслируется как `created`/`assembling`/`assembled`/`in_delivery`/`delivered`/`cancelled` — это внутренний формат интеграции, наружу он не отдаётся.

> Статус `draft` существует только при включённом модуле `draft_orders_until_payment`: заказ оформлен, но онлайн-оплата ещё не подтверждена. Такие заказы скрыты из `GET /orders`, `GET /orders/active` и `GET /orders/check-supplement`, наружу по RabbitMQ не публикуются и доступны только по прямому `GET /orders/{order}` и `GET /orders/{order}/payment-status`. Полное описание флоу — [`08_payments.md` § 4.8](./08_payments.md).

Переходы статусов вперёд выполняет курьерская система, storefront принимает их через RabbitMQ. Через клиентский API доступны только два перехода: `pending|assembling → cancelled` (отмена) и добавление товаров в `pending|assembling` (дозаказ).

**Индексы:** `user_id, status`; `store_id`; `client_order_id`; UNIQUE `user_id, idempotency_key`.

### 3.2 Модель OrderAddress (snapshot)

```
order_addresses
├── id: bigint PK
├── order_id: bigint FK → orders.id UNIQUE
├── original_id: bigint NULL                 // addresses.id
├── country: string(100) NOT NULL
├── city: string(100) NOT NULL
├── street: string(200) NULL                 // nullable (новое)
├── house_number: string(20) NULL            // nullable (новое)
├── entrance: string(10) NULL
├── floor: string(10) NULL
├── apartment: string(20) NULL
├── intercom: string(20) NULL
├── comment: string(500) NULL
├── full_address: string(500) NULL
├── object_data: json NULL                   // снапшот объекта адреса (новое)
├── latitude: decimal(10,7) NULL
├── longitude: decimal(10,7) NULL
└── created_at: timestamp
```

### 3.3 Модель OrderItem (snapshot)

```
order_items
├── id: bigint PK
├── parent_id: bigint FK NULL → order_items.id   // дочерняя позиция-добавка (новое)
├── source_supplement_value_id: bigint FK NULL   // из какого значения добавки (новое)
├── order_id: bigint FK → orders.id
├── product_id: bigint NULL
├── title: string(300) NOT NULL
├── sku: string(100) NOT NULL
├── price: decimal(12,2) NOT NULL
├── price_old: decimal(12,2) NULL             // цена до скидки, снапшот (новое)
├── quantity: int NOT NULL                    // в единице хранения
├── returned_quantity: int NULL DEFAULT 0     // возвращено курьеру (новое)
├── actual_weight: int unsigned NULL          // фактический вес, граммы
├── measurement_unit: string(20) NULL         // единица хранения quantity
├── total: decimal(12,2) NOT NULL
├── vat_rate: int NULL                        // ставка НДС, снапшот (новое)
├── image_url: string(500) NULL
├── status: string(20) NULL                   // статус позиции при сборке
├── eligible_for_bonus: boolean DEFAULT true  // снапшот «без скидки» (новое)
├── origin_product_id: bigint NULL            // замена: исходный товар
├── origin_title: string(300) NULL
├── origin_price: decimal(12,2) NULL
├── origin_quantity: int NULL
└── created_at: timestamp
```

**Индекс:** `order_id`

`status` заполняется на сборке через RabbitMQ. Встречающиеся значения: `added`, `confirmed`, `replaced`, `cancelled`; `null` — позиция из исходного заказа, по которой сборка ещё ничего не сообщила.

Для весовых товаров `quantity` хранится в граммах: при создании заказа килограммы конвертируются в граммы, а `measurement_unit` записывается как `gram`.

### 3.4 Модель OrderPromocode (snapshot)

```
order_promocodes
├── id: bigint PK
├── order_id: bigint FK → orders.id UNIQUE
├── original_id: bigint NULL                 // promocodes.id
├── code: string(50) NOT NULL
├── type: string(20) NOT NULL                // percent | fixed
├── value: int NOT NULL                      // % или рубли
├── discount_amount: decimal(12,2) NOT NULL  // Сумма скидки
└── created_at: timestamp
```

### 3.5 Модель OrderBonus (snapshot)

```
order_bonuses
├── id: bigint PK
├── order_id: bigint FK → orders.id UNIQUE
├── type: string(10) NOT NULL                // earn | spend
├── percent: tinyint unsigned NOT NULL       // процент магазина на момент заказа
├── eligible_amount: int NOT NULL            // база начисления/списания
├── amount: int NOT NULL                     // фактическая сумма
├── requested_amount: int NULL               // запрошено клиентом (новое)
└── created_at: timestamp
```

## 4. Логика

### 4.1 createOrder [AUTH] · throttle 5/min

**Endpoint:** `POST /api/v1/orders`

**Входные данные (полный набор):**
```json
{
  "storeId": 1,
  "addressId": 5,
  "deliverySlotId": 1735635600,
  "shippingMethod": "courier",
  "shippingTariffCode": "136",
  "comment": "Позвоните за 30 минут",
  "idempotencyKey": "550e8400-e29b-41d4-a716-446655440000",
  "paymentType": "card",
  "paymentCardId": 3,
  "bonusAction": "spend",
  "bonusSpendAmount": 500
}
```

**Валидация (`CreateOrderRequest`):**

| Поле | Правила | Примечание |
|---|---|---|
| `storeId` | required, integer, exists:stores,id | |
| `addressId` | при `shippingMethod` = `cdek`/`russian_post` — required; иначе `required_unless:shippingMethod,pickup`; nullable, integer; exists:addresses,id **с фильтром по user_id** | адрес чужого пользователя не проходит валидацию |
| `deliverySlotId` | при `cdek`/`russian_post` — nullable; иначе required; integer | Unix timestamp начала слота, не id записи БД |
| `shippingTariffCode` | required при `cdek`/`russian_post`; nullable, string, max 64 | код тарифа службы доставки (новое) |
| `comment` | nullable, string, max 1000 | |
| `idempotencyKey` | nullable, string, max 64 | camelCase |
| `shippingMethod` | sometimes, in `courier,pickup,cdek,russian_post`, default `courier` | (новое: `cdek`, `russian_post`) |
| `paymentType` | sometimes, in `cash,card` | legacy-поле `paymentMethod` мержится в `paymentType` в `prepareForValidation()`. Непереданное значение трактуется как `cash` |
| `paymentCardId` | nullable, integer, exists:payment_cards,id **с фильтром по user_id** | |
| `bonusAction` | nullable, in `earn,spend` | |
| `bonusSpendAmount` | `required_if:bonusAction,spend`, nullable, integer, min 1, max = `user.available_bonus_balance` | доступный баланс = `bonus_balance − bonus_reserved` (новое) |

Для `cdek`/`russian_post` дополнительно проверяется адрес: `additional.kind = "pickup"` и `additional.provider = shippingMethod`, иначе ошибка в `errors.addressId` («Адрес должен быть пунктом выдачи заказов (pickup). Укажите адрес ПВЗ.» / «Провайдер ПВЗ не совпадает с выбранным методом доставки.»).

**Заголовки (новое):** `X-Analytics-Session-Id` и `X-Analytics-Client-Id` необязательны; если переданы — обрезаются до 64 символов и сохраняются в заказе (`analytics_session_id`, `analytics_device_client_id`) для сквозной аналитики.

**Логика (`CreateOrderAction`):**
```
1.  Идемпотентность: заказ с таким idempotency_key у этого пользователя уже есть
    → 201 {orderId существующего заказа, confirmationUrl: null}, повторной обработки нет
2.  Корзина пользователя; позиции по store_id
    - корзины нет или позиций нет → 422 «Корзина пуста»
3.  Магазин: is_active = false → 422 «Магазин «N» временно не принимает заказы»
4.  Модуль b2b включён: B2C-пользователь + магазин is_b2b → 403 «Магазин недоступен»
5.  shippingMethod = pickup:
    - store.is_pickup_enabled = false → 422 «Самовывоз недоступен для этого магазина»
    - paymentType не входит в store.pickup_payment_methods (по умолчанию ['card'])
      → 422 «Выбранный способ оплаты недоступен для самовывоза»
6.  shippingMethod ≠ pickup и в позициях есть товар «только самовывоз»
    → 422 «В заказе есть товар, доступный только для самовывоза»
7.  Минимальная сумма:
    - pickup / ПВЗ → store.min_pickup_order_amount ?? store.min_order_amount
    - курьер → minOrderAmount зоны доставки (slots-api) ?? store.min_order_amount
    → 422 «Минимальная сумма заказа: X руб. Текущая сумма: Y руб.»
8.  Слот доставки:
    - cdek / russian_post — слот не запрашивается, delivery_slot_display = ""
    - pickup — слот сверяется со слотами самовывоза магазина (с учётом времени
      готовности товаров) → 422 «Слот доставки больше недоступен»
    - курьер — слот сверяется со slots-api (с составом корзины);
      если адрес попал в зону Яндекса, shipping_method = yandex_delivery:
        · paymentType = cash → 422 «При Яндекс-доставке оплата возможна только картой»
        · слот дальше yandex-delivery.max_due_hours (по умолчанию 4 ч) → 422
9.  Стоимость доставки:
    - pickup → 0
    - cdek / russian_post → цена тарифа провайдера (через slots-api) по весу заказа
    - yandex_delivery → оценка Яндекса; 0, если subtotal ≥ порога бесплатной доставки
    - курьер → цена зоны ?? store.delivery_price; 0, если subtotal ≥ порога
10. assembly_cost (модуль assembly_cost): цена сборки зоны ?? store.assembly_price;
    при выключенном модуле — 0
11. is_heavy (модуль heavy_orders): вес корневых позиций против порога магазина
12. Бонусы (preview до записи в БД): при bonusAction = spend
    → 422 bonus_spend_failed / insufficient_bonus_balance
13. paymentType = card + сохранённая карта: синхронный pre-auth холд на сумму
    ДО вычета бонусов; отказ банка → 422 payment_declined, заказ не создаётся
14. Транзакция:
    a. Резервирование товаров (см. § 7)
    b. Order: status = pending, is_paid = false
       (модуль draft_orders_until_payment + card без сохранённой карты:
        status = draft, awaiting_payment_until = now + draft_order_ttl_minutes;
        шаги e (списание попытки промокода) и g откладываются до подтверждения оплаты)
    c. OrderAddress — snapshot адреса (кроме самовывоза без адреса)
    d. OrderItems — snapshot позиций (цена, price_old, vat_rate, единица измерения,
       добавки и дочерние позиции)
    e. OrderPromocode + запись в promocode_usages, инкремент used_count
    f. OrderBonus + пересчёт bonus_earned/bonus_spent, резерв бонусов (hold)
    g. Очистка корзины и промокода для этого магазина
    h. YandexDeliveryClaim (для yandex_delivery)
    i. total = subtotal − discount − bonus_spent + delivery_cost + assembly_cost
15. Гонка двух запросов с одним idempotencyKey: возвращается заказ-победитель,
    pre-auth холд не отменяется (он принадлежит созданному заказу)
16. yandex_delivery: заявка в Яндекс; ошибка → заказ отменяется,
    422 yandex_delivery_unavailable
17. paymentType = card без pre-auth (новая карта / redirect-флоу):
    создаётся холд, в ответ возвращается confirmationUrl
```

**Ответ 201 Created:**
```json
{
  "orderId": 123,
  "confirmationUrl": "https://yookassa.ru/checkout/payments/..."
}
```

> `confirmationUrl` — string, может быть null. Заполнен при `paymentType: "card"` в redirect-флоу (URL платёжного шлюза YooKassa). Для `cash`, для оплаты сохранённой картой (pre-auth прошёл синхронно) и для идемпотентного повтора — `null`.

**Ошибки:**

| Ситуация | HTTP | Тело |
|---|---|---|
| Нет токена | 401 | стандартный ответ Sanctum |
| Магазин недоступен B2C-клиенту (модуль b2b) | 403 | `{"message":"Магазин недоступен","errors":{"storeId":[...]}}` |
| Корзина пуста | 422 | `{"message":"Корзина пуста","errors":{"cart":[...]}}` |
| Минимальная сумма не достигнута | 422 | `errors.subtotal` — «Минимальная сумма заказа: X руб. Текущая сумма: Y руб.» |
| Не хватает остатка товара | 422 | `errors.quantity` — «Товар 'T' недоступен в нужном количестве. Доступно: A, запрошено: B» |
| Магазин выключен | 422 | `errors.storeId` — «Магазин «N» временно не принимает заказы» |
| Слот недоступен | 422 | `errors.deliverySlotId` — «Слот доставки больше недоступен» |
| Самовывоз выключен у магазина | 422 | `errors.shippingMethod` — «Самовывоз недоступен для этого магазина» |
| Оплата не разрешена для самовывоза | 422 | `errors.paymentType` — «Выбранный способ оплаты недоступен для самовывоза» |
| Товар «только самовывоз» при доставке | 422 | `errors.shippingMethod` — «В заказе есть товар, доступный только для самовывоза» |
| Некорректный адрес ПВЗ | 422 | `errors.addressId` |
| Яндекс-доставка с оплатой наличными | 422 | `errors.paymentType` — «При Яндекс-доставке оплата возможна только картой» |
| Заявка Яндекса не создалась | 422 | `{"error":"yandex_delivery_unavailable","message":"Доставка Яндексом сейчас недоступна..."}` |
| Отказ банка по сохранённой карте | 422 | `{"error":"payment_declined","reason":"insufficient_funds","message":"..."}` — `reason` может быть null |
| Бонусы списать невозможно | 422 | `{"error":"bonus_spend_failed","message":"Невозможно списать бонусы"}` |
| Запрошено бонусов больше доступного баланса | 422 | `{"error":"insufficient_bonus_balance"}` |

> Машинный код отказа оплаты возвращается в поле `reason` (значения — коды `cancellation_details.reason` YooKassa, например `insufficient_funds`); текст `message` не является признаком конкретного кейса.

### 4.2 getOrders [AUTH]

**Endpoint:** `GET /api/v1/orders`

**Query параметры:**
- `page` — int, по умолчанию 1
- `perPage` — int, по умолчанию 20, максимум 100

**Логика:**
1. Заказы текущего пользователя, сортировка `created_at DESC`
2. Подгружаются связи: `store`, `items` (с товарами, добавками, дочерними позициями и оригиналами замен), `address`, `promocode`, `bonus`, `declinedHoldTransaction`

**Ответ 200:**
```json
{
  "hasMore": true,
  "data": [
    {
      "id": 123,
      "store": {...},
      "status": "pending",
      "items": [...],
      "address": {...},
      "promocode": {...},
      "subtotal": 500.00,
      "discount": 50.00,
      "bonusEarned": 15,
      "bonusSpent": 100,
      "deliveryCost": 200.00,
      "assemblyCost": 150.00,
      "total": 800.00,
      "bonus": {...},
      "itemsCount": 3,
      "weightTotal": 12.5,
      "isHeavy": true,
      "couriersCount": 2,
      "isJointDelivery": true,
      "deliverySlotTimeSlot": "10:00-12:00",
      "deliverySlotDate": "2026-04-20",
      "isPaid": false,
      "paymentType": "card",
      "shippingMethod": "courier",
      "isB2b": false,
      "comment": "Позвоните заранее",
      "pickupTimerExpiresAt": null,
      "storeAddress": null,
      "createdAt": "2026-04-15T10:30:00+00:00",
      "heldAmount": 800.00,
      "capturedAmount": null,
      "refundedAmount": null,
      "surchargeAmount": null,
      "paymentDeclineReason": null
    }
  ],
  "total": 5
}
```

**Поля `OrderResource`:**

| Поле | Тип | Описание |
|---|---|---|
| `id` | int | |
| `store` | object | `StoreResource`. Ключ отсутствует, если связь не загружена; во всех ручках заказов она загружается |
| `status` | string | `pending`, `assembling`, `assembled`, `inDelivery`, `delivered`, `cancelled` |
| `items` | array | Только корневые позиции; дочерние позиции-добавки свёрнуты в `supplements[]` внутри родительской. Позиция-замена ставится перед заменённой |
| `address` | object\|null | `OrderAddressResource`; null — у заказа нет снапшота адреса (самовывоз) |
| `promocode` | object\|null | `OrderPromocodeResource`: `id`, `code`, `type` (`percent`\|`fixed`), `value` (int), `discountAmount` (float); null — промокода не было |
| `subtotal` | float | С копейками |
| `discount` | float | С копейками |
| `bonusEarned` | int | Целые рубли |
| `bonusSpent` | int | Целые рубли |
| `deliveryCost` | float | |
| `assemblyCost` | float | Стоимость сборки; 0 при выключенном модуле `assembly_cost` (новое) |
| `total` | float | `subtotal − discount − bonusSpent + deliveryCost + assemblyCost` |
| `bonus` | object\|null | `OrderBonusResource`: `type` (`earn`\|`spend`), `percent` (int), `eligibleAmount` (int), `amount` (int); null — бонусы к заказу не применялись |
| `itemsCount` | int | Число корневых позиций |
| `weightTotal` | float\|null | Вес заказа в кг по корневым позициям; null, если вес недоступен или связь `items` не загружена (новое) |
| `isHeavy` | bool | Признак «Тяжёлый заказ»; при выключенном модуле `heavy_orders` у новых заказов false (новое) |
| `couriersCount` | int | Число курьеров на доставке, приходит из курьерской системы (новое) |
| `isJointDelivery` | bool | `couriersCount > 1` (новое) |
| `deliverySlotTimeSlot` | string\|null | «10:00-12:00»; для ПВЗ — пустая строка |
| `deliverySlotDate` | string\|null | `Y-m-d` |
| `isPaid` | bool | |
| `paymentType` | string | `cash` \| `card` |
| `shippingMethod` | string | `courier`, `pickup`, `yandex_delivery`, `cdek`, `russian_post` |
| `isB2b` | bool | Из магазина заказа |
| `comment` | string\|null | |
| `pickupTimerExpiresAt` | string\|null | ISO 8601, дедлайн получения самовывоза до автоотмены |
| `storeAddress` | string\|null | Присутствует **только** при `shippingMethod = pickup` (адрес магазина, fallback — название) |
| `createdAt` | string | ISO 8601 |
| `heldAmount` | float\|null | Сумма холда |
| `capturedAmount` | float\|null | Списано |
| `refundedAmount` | float\|null | Возвращено |
| `surchargeAmount` | float\|null | Требуемая доплата после сборки (новое) |
| `paymentDeclineReason` | string\|null | Машинный код отказа онлайн-оплаты из последнего отменённого холда. Связь `declinedHoldTransaction` загружается в `index`, `show`, `cancel`, `active`, поэтому поле присутствует; null — отказа не было (новое) |
| `paymentCard` | object\|null | Присутствует только при загруженной связи `paymentCard`. В `index`, `show`, `cancel`, `supplement`, `active` связь не загружается, поэтому поля в ответе нет |

**Поля `OrderItemResource`:**

| Поле | Тип | Описание |
|---|---|---|
| `id` | int | |
| `productId` | int | |
| `title`, `sku` | string | Снапшот |
| `price` | float | Цена за единицу с копейками |
| `priceOld` | float\|null | Цена до скидки на момент оформления (новое) |
| `discountPercent` | int\|null | Считается из `priceOld`/`price`; null, если скидки не было (новое) |
| `quantity` | int | В единице хранения |
| `total` | float | |
| `quantityStep` | int | Квант товара |
| `cartQuantity` | int | Количество в единицах корзины (для повтора заказа) |
| `displayQuantity` | string | Готовая строка, например «1.5 кг» |
| `measurementUnit` | string | Единица отображения |
| `measurementUnitLabel` | string | Локализованное название единицы |
| `imageUrl` | string\|null | Актуальное фото товара, fallback — снапшот |
| `actualWeight` | int\|null | Фактический вес позиции в граммах |
| `status` | string\|null | Статус позиции при сборке |
| `origin` | object\|null | Заполняется у позиции-замены: `productId` (int\|null), `title`, `price` (float\|null, **число**), `quantity` (int\|null), `imageUrl` (string\|null) |
| `supplements` | array | Виртуальные добавки + товарные добавки из дочерних позиций |

**Поля `OrderAddressResource`:** `id`, `country`, `city`, `street`, `houseNumber`, `entrance`, `floor`, `apartment`, `intercom`, `comment`, `fullAddress`, `latitude` (float\|null), `longitude` (float\|null), `objectData` (object\|null — заполняется только при включённом модуле `address_objects` и непустом снапшоте объекта).

### 4.3 getOrderById [AUTH]

**Endpoint:** `GET /api/v1/orders/{orderId}`

**Логика:**
1. Заказ ищется по ID (мягко удалённые не находятся)
2. `OrderPolicy::view` — заказ принадлежит текущему пользователю
3. Подгружаются те же связи, что и в списке
4. Возвращается `{"data": OrderResource}`

**Ошибки:**
- 404 — заказ не найден
- 403 — чужой заказ

### 4.4 cancelOrder [AUTH] · throttle 10/min

**Endpoint:** `POST /api/v1/orders/{orderId}/cancel`

**Логика:**
1. `OrderPolicy::view` — заказ принадлежит текущему пользователю (иначе 403)
2. Статус допускает отмену: `pending`, `assembling` (иначе 422)
3. Если по заказу есть заявка Яндекс-доставки и курьер уже прибыл — отмена отклоняется (422, `errors.order`)
4. В транзакции: возврат товаров на склад, возврат бонусов (списанные — обратно на баланс, резерв — освобождается, начисленные — отменяются), статус `cancelled`, отмена заявки Яндекса, публикация события `order_cancelled`
5. Деньги при `payment_type = card`: списанные средства возвращаются рефандом, незакрытый холд снимается. Сбой платёжного шлюза не откатывает отмену заказа
6. Возвращается `{"data": OrderResource}` с обновлённым статусом

Для marketplace sub-order'а (`client_order_id` заполнен, режим `marketplace_mode`) бонусы и консолидированный холд на этом шаге не трогаются — они обрабатываются на уровне клиентского заказа (см. `21_client_orders_marketplace.md`).

**Ошибки:**
- 403 — чужой заказ
- 422 — «Заказ нельзя отменить в статусе 'X'» (`errors.status`)
- 422 — «Отмена невозможна — курьер Яндекс уже прибыл» (`errors.order`)

### 4.5 getActiveOrder [AUTH]

**Endpoint:** `GET /api/v1/orders/active`

Возвращает последний заказ пользователя, не находящийся в статусе `cancelled` или `delivered`, и общее количество таких заказов.

**Response 200:** `{"data": OrderResource|null, "totalActive": 2}`. Если активных заказов нет — `{"data": null, "totalActive": 0}`.

Связи в этом ответе загружаются в сокращённом наборе (`store`, `items`, `address`, `promocode`, `bonus`, `declinedHoldTransaction`): добавки и оригиналы замен внутри позиций не подгружаются.

### 4.6 checkSupplement [AUTH]

**Endpoint:** `GET /api/v1/orders/check-supplement`

Проверка наличия заказа, который можно дополнить, в указанном магазине.

**Query:** `storeId` — required, integer, exists:stores,id. Snake_case `store_id` принимается как устаревший алиас (мержится в `storeId`).

**Логика:** ищется последний по `created_at` заказ пользователя в этом магазине со статусом `pending` или `assembling`.

**Response 200:**
```json
{"order_id": 123, "can_supplement": true}
```
Либо `{"order_id": null, "can_supplement": false}`, если такого заказа нет. Ключи ответа — snake_case.

**Ошибки:** 422 — «Укажите магазин» / «Магазин не найден».

### 4.7 supplementOrder [AUTH] · throttle 5/min

**Endpoint:** `POST /api/v1/orders/{orderId}/supplement`

Добавляет в существующий заказ товары, лежащие в корзине пользователя для магазина этого заказа. Состав в теле запроса не передаётся. Подробности — в `order_supplement_spec.md`.

**Body:**
```json
{
  "idempotencyKey": "550e8400-e29b-41d4-a716-446655440000"
}
```
> `idempotencyKey` — nullable, string, max 64. Snake_case `idempotency_key` принимается как устаревший алиас. Ключ действует 24 часа: повторный вызов с тем же ключом по тому же заказу возвращает заказ без повторного добавления.

**Логика:**
1. `OrderPolicy::supplement` — заказ принадлежит пользователю **и** статус `pending`/`assembling` (иначе 403)
2. В транзакции заказ блокируется, статус проверяется повторно (иначе 409)
3. Корзина пуста для магазина заказа → 422
4. У заказа `shipping_method` ≠ `pickup` и среди добавляемых товаров есть «только самовывоз» → 422
5. Товары резервируются; совпадающие по товару и набору добавок позиции увеличиваются в количестве, остальные добавляются новыми
6. Пересчитываются `subtotal`, `discount` (по снапшоту промокода), `items_count` и `total` (с учётом `assembly_cost`)
7. Корзина для этого магазина очищается, публикуется событие `order_supplemented`

> После дозаказа `items_count` считается по всем позициям заказа, включая дочерние позиции-добавки, тогда как при создании заказа в него попадают только корневые позиции.

**Response 200:** `{"data": OrderResource}`. В этом ответе загружаются только связи `store`, `items` (с виртуальными добавками), `address`, `promocode`: полей `bonus`, `paymentDeclineReason` и `paymentCard` в нём нет, товарные добавки из дочерних позиций в `supplements[]` не свёрнуты. Актуальное состояние заказа целиком отдаёт `GET /orders/{orderId}`.

**Ошибки:**
- 403 — чужой заказ ИЛИ статус не допускает дополнения
- 409 — статус изменился между проверкой прав и транзакцией: «Заказ нельзя дополнить в статусе 'X'» (`errors.status`)
- 422 — корзина пуста / не хватает остатка / товар «только самовывоз» в заказе с доставкой

### 4.8 Оплата, возврат и чеки [AUTH]

Эндпоинты `POST /api/v1/orders/{orderId}/pay` (throttle 5/мин), `POST /api/v1/orders/{orderId}/refund` (throttle 5/мин) и `GET /api/v1/orders/{orderId}/receipts` относятся к платёжному контуру — контракт описан в [`08_payments.md`](./08_payments.md).

Влияние платёжного контура на контракт заказа: поля `isPaid`, `heldAmount`, `capturedAmount`, `refundedAmount`, `surchargeAmount`, `paymentDeclineReason` в `OrderResource` (§ 4.2). При включённом модуле `collector_payment` списание выполняется в момент завершения сборки через внутренний межсервисный вызов (`POST /v1/internal/orders/{order}/settle-payment`, middleware `internal.token`, мобильным клиентом не используется). В клиентском контракте результат виден как изменение `isPaid`/`capturedAmount`, а при неудавшейся доплате — как ненулевой `surchargeAmount`. Дедлайн доплаты хранится в БД (`surcharge_expires_at`) и в ответах заказа не отдаётся; по его истечении заказ отменяется фоновой командой. Отдельной клиентской ручки для этого сценария нет.

### 4.9 Отзыв на заказ [AUTH]

- `GET /api/v1/orders/{orderId}/review` — отзыв текущего пользователя на заказ; 404 `{"message": "Отзыв не найден"}`, если отзыва нет
- `POST /api/v1/orders/{orderId}/review` — создание, throttle 10/мин, ответ 201 `{"data": OrderReviewResource}`
- `PUT /api/v1/orders/{orderId}/review` — обновление, throttle 10/мин, ответ 200 `{"data": OrderReviewResource}`; 404, если отзыва ещё нет

Тело `POST`/`PUT`: `rating` — required, integer, 1..5; `description` — nullable, string, max 1000. Права на создание проверяет политика `OrderReview::createForOrder`. Полный контракт отзывов — в [`15_reviews.md`](./15_reviews.md).

## 5. Нефункциональные требования

### Транзакции
- `createOrder` — резервирование товаров, создание заказа, снапшотов и очистка корзины выполняются в одной транзакции; при ошибке декремент остатков откатывается
- `supplementOrder` — блокировка заказа `lockForUpdate` + повторная проверка статуса внутри транзакции
- `cancelOrder` — возврат товаров, бонусов и смена статуса в одной транзакции; операции платёжного шлюза выполняются best-effort после неё

### Идемпотентность
- `POST /orders` — ключ `idempotencyKey` (camelCase) в теле запроса; уникальность в БД по паре `user_id + idempotency_key`. Повторный вызов возвращает существующий заказ с `confirmationUrl: null`
- `POST /orders/{order}/supplement` — ключ `idempotencyKey` (camelCase, snake_case-алиас поддерживается) в теле запроса, хранится 24 часа в `order_supplement_idempotency`
- В заголовках ключ идемпотентности не читается

### Rate limits
- `POST /orders` — 5/мин
- `POST /orders/{order}/cancel` — 10/мин
- `POST /orders/{order}/supplement` — 5/мин
- `POST /orders/{order}/pay` — 5/мин
- `POST /orders/{order}/refund` — 5/мин
- `POST|PUT /orders/{order}/review` — 10/мин
- На `GET`-ручки заказов throttle не установлен

### Бизнес-правила
- Заказ с пустой корзиной не создаётся
- Заказ ниже минимальной суммы не создаётся
- Отмена возможна в статусах `pending` и `assembling`
- Дополнение возможно в статусах `pending` и `assembling`
- Использование промокода фиксируется в момент создания заказа; отменённый заказ лимиты промокода не расходует, клиент может применить промокод повторно (кто бы ни отменил заказ: клиент, курьерка или админка)

## 6. Реализация

### Код
- Миграции: `create_orders_table`, `create_order_addresses_table`, `create_order_items_table`, `create_order_promocodes_table`, `create_order_bonuses_table`, `create_order_supplement_idempotency_table` + серия alter-миграций (бонусы, платежи, доставка ПВЗ, тяжёлый заказ, стоимость сборки, доплата, аналитика)
- Модели: `Order`, `OrderItem`, `OrderAddress`, `OrderPromocode`, `OrderBonus`, `OrderItemSupplement`, `OrderSupplementIdempotency`
- Контроллеры: `OrderController`, `OrderReviewController`, `OrderReceiptController`, `InternalOrderPaymentController`
- Actions: `CreateOrderAction`, `CancelOrderAction`, `SupplementOrderAction`, `ReserveProductsAction`, `ReleaseProductsAction`, `AutoCancelPickupOrderAction`
- Resources: `OrderResource`, `OrderItemResource`, `OrderAddressResource`, `OrderPromocodeResource`, `OrderBonusResource`, `OrderItemSupplementResource`
- Policy: `OrderPolicy` (`view`, `cancel`, `append`, `supplement`)

### Тесты
- `tests/Feature/Api/V1/Order/` — `OrderTest`, `OrderSupplementTest`, `OrderSupplementsTest`, `CdekRussianPostOrderTest`, `PreauthorizedCardOrderTest`, `PromocodeUsageTrackingTest`, `OrderReceiptsTest`, `ClientOrderBonusTest`
- `tests/Feature/Order/` — `CreateOrderPickupOnlyRestrictionTest`, `SupplementOrderPickupOnlyRestrictionTest`, `CreateOrderAssemblyCostTest`, `CancelOrderRefundTest`, `AutoCancelPickupRefundTest`, `CancelExpiredSurchargeTest`, `CatalogSupplementOrderTest`, `CreateOrderPassesCartProductsTest`
- `tests/Feature/` — `CreateOrderHeavyFlagTest`, `OrderResourceHeavyFieldsTest`, `OrderAssemblyCostRecalcTest`, `OrderAnalyticsHeadersTest`, `OrderReviewTest`

### Routes
```php
Route::middleware('auth:sanctum')->prefix('orders')->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/active', [OrderController::class, 'getActive']);
    Route::get('/check-supplement', [OrderController::class, 'checkSupplement']);
    Route::get('/{order}', [OrderController::class, 'show']);
    Route::post('/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:10,1');
    Route::post('/{order}/supplement', [OrderController::class, 'supplement'])->middleware('throttle:5,1');
    Route::post('/{order}/pay', [OrderController::class, 'pay'])->middleware('throttle:5,1');
    Route::post('/{order}/refund', [OrderController::class, 'refund'])->middleware('throttle:5,1');
    Route::get('/{order}/receipts', [OrderReceiptController::class, 'index']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('orders/{order}/review', [OrderReviewController::class, 'show']);
    Route::post('orders/{order}/review', [OrderReviewController::class, 'store'])->middleware('throttle:10,1');
    Route::put('orders/{order}/review', [OrderReviewController::class, 'update'])->middleware('throttle:10,1');
});
```

> `POST /{order}/append` не существует.

---

## 7. Резервация и списание товаров

### 7.1 Описание процесса

При оформлении заказа товары резервируются: строка товара блокируется `lockForUpdate`, остаток проверяется, а для товаров в режиме учёта `fixed` уменьшается `products.quantity`.

### 7.2 Резервация при создании заказа

`ReserveProductsAction::execute()` выполняется внутри транзакции создания заказа:

- дочерняя позиция-добавка, у которой значение добавки снимает учёт остатка (`track_stock` override), пропускается;
- товар не найден → `InsufficientStockException` (доступно 0);
- у товара учитывается остаток (`stock_mode` ≠ `ignore` и у магазина не включён `ignore_stock`) и `quantity < requested` → `InsufficientStockException` → 422;
- `quantity` уменьшается **только** при `stock_mode = 'fixed'` — это дневной лимит, который ведётся вручную. Режимы `normal` (остатком управляет 1С) и `ignore` (остаток не учитывается) не декрементятся.

```php
$product = Product::lockForUpdate()->find($item->product_id);

if (! $product->isStockIgnored() && $product->quantity < $item->quantity) {
    throw new InsufficientStockException(
        productTitle: $product->title,
        available: $product->quantity,
        requested: $item->quantity,
    );
}

if ($product->stock_mode === 'fixed') {
    $product->decrement('quantity', $item->quantity);
}
```

### 7.3 Возврат при отмене заказа

При отмене заказа (статусы `pending`, `assembling`) количество возвращается на склад в собственной транзакции с блокировкой строк. Товары, по которым остаток не ведётся (`stock_mode = 'ignore'` или `ignore_stock` у магазина), пропускаются — иначе синхронизированный из 1С остаток раздувался бы.

```php
foreach ($order->items as $item) {
    if ($item->product_id) {
        $product = Product::lockForUpdate()->find($item->product_id);

        if ($product && ! $product->isStockIgnored()) {
            $product->increment('quantity', $item->quantity);
        }
    }
}
```
