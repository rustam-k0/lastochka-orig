# ТЗ: Клиентские заказы (Marketplace режим)

> **Только для marketplace-режима.** Все эндпоинты защищены middleware `marketplace.mode`: при выключенном режиме — `404 Not Found`. См. `00_overview.md` → «Marketplace-режим».

## 1. Контекст

В marketplace-режиме один клиентский заказ может содержать товары из нескольких магазинов-партнёров. На бэке это **N `Order`** (sub-orders, по одному на магазин), объединённых одним `client_order_id` (UUID). Отдельной таблицы `client_orders` нет: агрегат собирается на лету из sub-order'ов, поля «уровня клиентского заказа» (адрес, способ доставки, комментарий) берутся с первого sub-order'а.

Поэтому создание заказа — **один** POST на всю корзину. Ответ — `clientOrderId` + идентификаторы sub-order'ов.

Оплата картой консолидированная: sub-order'ы создаются с `payment_type = cash` (это внутренний приём, отключающий per-order холд), а один холд YooKassa на сумму всех sub-order'ов создаётся на уровне клиентского заказа.

## 2. Endpoints

### 2.1 GET /api/v1/client-orders [AUTH]

Список клиентских заказов текущего пользователя: все заказы с непустым `client_order_id`, сгруппированные по нему, в порядке убывания `id`. Throttle не установлен.

**Response 200:** `{"data": [ClientOrder, ...]}` — массив агрегатов, поля описаны в § 3.

### 2.2 POST /api/v1/client-orders [AUTH]

Создать клиентский заказ из всей корзины. **Throttle:** 5/мин.

**Body:**
```json
{
  "addressId": 5,
  "paymentType": "card",
  "paymentCardId": 3,
  "shippingMethod": "courier",
  "deliverySlots": [
    {"storeId": 1, "slotId": 1735635600},
    {"storeId": 2, "slotId": 1735635900}
  ],
  "shippingTariffCodes": "136",
  "promocodeIds": [42],
  "bonusAction": "spend",
  "bonusSpendAmount": 400,
  "comment": "Позвонить перед доставкой",
  "idempotencyKey": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Поля (`CreateClientOrderRequest`):**

| Поле | Правила | Примечание |
|---|---|---|
| `addressId` | required, integer, exists:addresses,id | Принадлежность адреса пользователю на этом эндпоинте не проверяется |
| `paymentType` | required, string, in `card,cash` | В `POST /orders` набор тот же (`cash`, `card`) |
| `paymentCardId` | nullable, integer, exists:payment_cards,id | Используется для консолидированного холда |
| `shippingMethod` | sometimes, string, in `courier,pickup,cdek,russian_post`, default `courier` | (новое) |
| `deliverySlots` | required, array, min 1 — для `courier`/`pickup`; `sometimes` и игнорируется для `cdek`/`russian_post` | По одному элементу на магазин |
| `deliverySlots.*.storeId` | required (кроме ПВЗ), integer | |
| `deliverySlots.*.slotId` | required (кроме ПВЗ), integer | Unix timestamp начала слота |
| `shippingTariffCodes` | required при `cdek`/`russian_post`; nullable | Строка (один тариф на все магазины) либо map `storeId → code`; каждое значение — непустая строка ≤ 64 символов (новое) |
| `promocodeIds` | sometimes, array; элементы — integer, exists:promocodes,id | Принимается и валидируется, но на расчёт заказа не влияет: промокоды берутся из корзины |
| `bonusAction` | nullable, string, in `earn,spend` | (новое) |
| `bonusSpendAmount` | `required_if:bonusAction,spend`, nullable, integer, min 1, max = `user.available_bonus_balance` | Доступный баланс = `bonus_balance − bonus_reserved` (новое) |
| `comment` | nullable, string, max 500 | |
| `idempotencyKey` | nullable, string, max 100 | В `POST /orders` предел — 64 |

Для `cdek`/`russian_post` дополнительно проверяется адрес: `additional.kind = "pickup"` и `additional.provider = shippingMethod`, иначе ошибка в `errors.addressId`.

**Логика (`CreateClientOrderAction`):**
```
1. Идемпотентность: sub-order'ы создаются с ключами вида "<key>:0", "<key>:1".
   Если заказ с ключом "<key>:0" и заполненным client_order_id уже есть —
   возвращается агрегат существующего клиентского заказа, confirmationUrl = null
2. Корзина пользователя; её отсутствие → 422 «Корзина пуста»
3. Группы магазинов:
   - courier / pickup — из deliverySlots[]
   - cdek / russian_post — уникальные store_id позиций корзины, slotId не используется
4. pickup: у каждого магазина группы проверяется is_pickup_enabled и допустимость
   реального paymentType по store.pickup_payment_methods (по умолчанию ['card'])
5. cdek / russian_post: включён соответствующий модуль, у каждого магазина активна
   интеграция службы доставки, для каждого магазина задан тариф
6. bonusAction вместе с платформенным промокодом в корзине → 422
7. Распределение бонусов по магазинам:
   - earn — всем магазинам с включёнными бонусами
   - spend — жадно по порядку групп: доля магазина = min(остаток,
     floor(сумма товаров без скидки × bonus_spend_percent / 100));
     магазины со своим (store-scoped) промокодом пропускаются
   - распределить не удалось → 422 bonus_spend_failed
8. Транзакция: по каждой группе выполняется создание обычного заказа
   (та же логика, что в POST /orders: остатки, минимальная сумма, слот,
   ограничение «только самовывоз», снапшоты), затем всем созданным заказам
   проставляется client_order_id, а использование платформенного промокода
   фиксируется один раз на весь клиентский заказ
9. После коммита при paymentType = card создаётся консолидированный холд YooKassa
   на сумму всех sub-order'ов, в ответ идёт confirmationUrl
```

Любая ошибка при создании отдельного sub-order'а откатывает весь клиентский заказ: ни один sub-order не остаётся в БД.

**Response 200 (не 201):**
```json
{
  "clientOrderId": "550e8400-e29b-41d4-a716-446655440000",
  "subOrderIds": [1001, 1002],
  "totalAmount": 14800.00,
  "consolidatedStatus": "pending",
  "confirmationUrl": "https://yookassa.ru/checkout/payments/..."
}
```

| Поле | Тип | Описание |
|---|---|---|
| `clientOrderId` | string | UUID клиентского заказа |
| `subOrderIds` | int[] | Идентификаторы созданных sub-order'ов |
| `totalAmount` | float | Сумма `total` всех sub-order'ов, с копейками |
| `consolidatedStatus` | string | Консолидированный статус (§ 3.1) |
| `confirmationUrl` | string\|null | URL платёжного шлюза при `paymentType = card`; null для `cash`, для оплаты сохранённой картой и для идемпотентного повтора |

**Ошибки:**

| Ситуация | HTTP | Тело |
|---|---|---|
| Режим marketplace выключен | 404 | — |
| Нет токена | 401 | стандартный ответ Sanctum |
| Корзина пуста / нет слотов доставки | 422 | `errors.cart` — «Корзина пуста» |
| Самовывоз недоступен у части магазинов | 422 | `errors.shippingMethod` — «Самовывоз недоступен для части товаров корзины.» |
| Способ оплаты недоступен для самовывоза | 422 | `errors.paymentType` — «Выбранный способ оплаты недоступен для самовывоза части товаров корзины.» |
| Модуль СДЭК / Почты России выключен | 422 | `errors.shippingMethod` — «Выбранный способ доставки сейчас недоступен.» |
| Нет активной интеграции службы доставки у части магазинов | 422 | `errors.shippingMethod` — «Часть товаров корзины недоступна для выбранного способа доставки. Уберите их или выберите другой способ.» |
| Тариф задан не для всех магазинов | 422 | `errors.shippingTariffCodes` — «Не указан тариф доставки для всех товаров корзины.» |
| Бонусы вместе с платформенным промокодом | 422 | `errors.bonusAction` — «Бонусы нельзя использовать вместе с промокодом.» |
| Бонусы распределить невозможно | 422 | `{"error":"bonus_spend_failed","message":"Невозможно списать бонусы"}` |
| Ошибки уровня отдельного магазина (остатки, минимальная сумма, слот, товар «только самовывоз») | 422 | Тела как в `06_orders.md` § 4.1 |

### 2.3 GET /api/v1/client-orders/{clientOrderId} [AUTH]

Детали одного клиентского заказа. Throttle не установлен.

**Response 200:** объект агрегата (§ 3) **без обёртки `data`**.

**Response 404:** `{"error": "not_found"}` — клиентского заказа нет либо он принадлежит другому пользователю.

### 2.4 DELETE /api/v1/client-orders/{clientOrderId} [AUTH]

Полная отмена клиентского заказа. **Throttle:** 10/мин.

**Логика (`CancelClientOrderAction`):**

Sub-order'ы выбираются по `user_id` + `client_order_id`; пустой результат → 404. Дальнейшие шаги выполняются одной транзакцией:

1. Каждый sub-order отменяется, если его статус допускает отмену (`pending`, `assembling`); sub-order'ы в терминальных статусах пропускаются, отмена остальных продолжается. По каждому возвращаются товары на склад и публикуется событие отмены
2. Освобождаются бонусные резервы sub-order'ов
3. Фактически списанные бонусы возвращаются одной агрегированной транзакцией на `client_order_id` (повторный вызов рефанд не дублирует)
4. Консолидированный холд YooKassa снимается один раз — при `payment_type = card` у первого sub-order'а, ненулевом `held_amount` и нулевом `captured_amount`

**Response 200:**
```json
{"status": "cancelled", "clientOrderId": "550e8400-e29b-41d4-a716-446655440000"}
```

**Response 404:** клиентского заказа с таким id у пользователя нет.

### 2.5 GET /api/v1/cart/delivery-summary [AUTH]

Агрегированная сводка доставки по всей корзине. Реализована в контроллере корзины; описание `availableShippingMethods` — в `05_cart.md`.

**Query:** `addressId` — необязательный int. Если не передан, берётся активный адрес пользователя с координатами.

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

| Поле | Тип | Описание |
|---|---|---|
| `totalShippingPrice` | float | Сумма стоимости доставки по магазинам корзины; 0, если сумма корзины достигла порога бесплатной доставки marketplace |
| `freeShippingFrom` | int\|null | Порог бесплатной доставки marketplace; null — порог не задан |
| `estimatedDates` | string[] | Даты `Y-m-d` первых доступных слотов по магазинам, по возрастанию |
| `isMultiDate` | bool | Дат больше одной |
| `dateGroups` | array | Всегда пустой массив |
| `availableShippingMethods` | array | Доступность 4 способов доставки для текущего состава корзины (новое) |

Магазины, по которым внешняя система слотов недоступна, в расчёт не попадают — ответ отдаётся по остальным.

**Response 404:** `{"error": "address_not_found"}` — переданный `addressId` не существует или принадлежит другому пользователю.

## 3. Структура агрегата (`ClientOrderResource`)

Отдаётся в `GET /client-orders` (внутри `data[]`) и в `GET /client-orders/{clientOrderId}` (корнем ответа).

| Поле | Тип | Описание |
|---|---|---|
| `clientOrderId` | string | UUID |
| `subOrderIds` | int[] | Идентификаторы sub-order'ов |
| `consolidatedStatus` | string | Консолидированный статус (§ 3.1) |
| `subtotal` | float | Сумма по sub-order'ам, с копейками |
| `discount` | float | Сумма скидок |
| `deliveryCost` | float | Сумма стоимости доставки |
| `assemblyCost` | float | Сумма стоимости сборки; 0 при выключенном модуле `assembly_cost` (новое) |
| `totalAmount` | float | Сумма итогов |
| `itemsCount` | int | Число позиций во всех sub-order'ах, включая дочерние позиции-добавки |
| `items` | array | Позиции всех sub-order'ов (`OrderItemResource`, см. `06_orders.md` § 4.2), включая дочерние; позиция-замена ставится перед заменённой |
| `createdAt` | string\|null | ISO 8601, дата первого sub-order'а |
| `shippingMethod` | string\|null | Способ доставки первого sub-order'а |
| `address` | object\|null | `OrderAddressResource` первого sub-order'а |
| `paymentMethod` | string\|null | `card`, если по клиентскому заказу есть платёжные транзакции; иначе `payment_type` первого sub-order'а (для marketplace это `cash`) |
| `paymentStatus` | string | `refunded`, `paid`, `hold`, `failed`, `pending` — по платёжным транзакциям клиентского заказа; при их отсутствии `pending` |
| `paymentDeclineReason` | string\|null | Машинный код отказа онлайн-оплаты из последнего отменённого холда; null — отказа не было (новое) |
| `paidAt` | string\|null | ISO 8601 успешного списания |
| `comment` | string\|null | Комментарий первого sub-order'а |
| `recipient` | null | Зарезервировано, всегда null |
| `additionalServices` | array | Зарезервировано, всегда пустой массив |
| `vatAmount` | null | Зарезервировано, всегда null |
| `weightTotal` | float\|null | Вес всех позиций в кг; null, если вес недоступен |
| `isHeavy` | bool | Хотя бы один sub-order помечен как тяжёлый (новое) |
| `couriersCount` | int | Максимум `couriers_count` среди sub-order'ов (новое) |
| `isJointDelivery` | bool | `couriersCount > 1` (новое) |
| `bonusSpend` | int | Сумма списанных бонусов, целые рубли |
| `bonusEarn` | int | Сумма начисленных бонусов, целые рубли |
| `promocode` | object\|null | `{"code": "...", "discount": 500.00}` — код первого промокода среди sub-order'ов и сумма скидок; null, если промокода не было |
| `progressStatus` | object | `{"current": "<consolidatedStatus>", "sequence": ["pending","assembling","assembled","inDelivery","delivered"]}`; `cancelled` в последовательность не входит (новое) |
| `subOrders` | array | Разбивка по магазинам (§ 3.2) |

### 3.1 Консолидированный статус

Правило «минимальный прогресс» по шкале `pending < assembling < assembled < inDelivery < delivered`:

- sub-order'ов нет → `pending`
- все sub-order'ы `cancelled` → `cancelled`
- иначе — минимальный статус среди не отменённых; отменённые sub-order'ы игнорируются

### 3.2 Элемент `subOrders[]`

| Поле | Тип | Описание |
|---|---|---|
| `id` | int | ID sub-order'а |
| `storeId` | int | |
| `status` | string | Статус sub-order'а (`OrderStatus`) |
| `subtotal` | float | |
| `deliveryCost` | float | |
| `assemblyCost` | float | (новое) |
| `total` | float | |
| `deliverySlot` | object | `{"date": "Y-m-d"\|null, "timeFrom": "H:i"\|null, "timeTo": "H:i"\|null, "display": string\|null}` |
| `pickupStore` | object\|null | Только при `shippingMethod = pickup`: `{"id", "name", "address", "description"}`; `description` — описание магазина (новое) |
| `items` | array | Позиции этого sub-order'а |

## 4. Routes

```php
Route::middleware(['auth:sanctum', 'marketplace.mode'])->prefix('client-orders')->group(function () {
    Route::get('/', [ClientOrderController::class, 'index']);
    Route::post('/', [ClientOrderController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/{clientOrderId}', [ClientOrderController::class, 'show']);
    Route::delete('/{clientOrderId}', [ClientOrderController::class, 'destroy'])->middleware('throttle:10,1');
});

Route::middleware(['auth:sanctum', 'marketplace.mode'])
    ->get('cart/delivery-summary', [CartController::class, 'deliverySummary']);
```

См. также: `05_cart.md`, `06_orders.md`, `19_bonuses.md`, `08_payments.md`.
