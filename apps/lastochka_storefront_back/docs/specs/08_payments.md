# ТЗ: Платежи

## 1. Цель

Онлайн-оплата заказа банковской картой через ЮKassa по двухстадийной схеме
(холд, списание, возврат), хранение привязанных карт клиента и фискализация
операций по 54-ФЗ через АТОЛ Онлайн или ЮKassa.

## 2. Контекст

- **Модуль:** Payment
- **Платёжный шлюз:** ЮKassa (`https://api.yookassa.ru/v3`), реализация `YooKassaService` за интерфейсом `PaymentGateway`
- **Фискализация:** интерфейс `Fiscalizer`, реализации `AtolFiscalizer` и `YooKassaFiscalizer`, выбор по драйверу интеграции `fiscal`
- **Схема оплаты картой:** холд при оформлении, списание при завершении сборки, возврат при отмене или частичной доставке
- **Оплата наличными:** без обращения в ЮKassa, факт доставки означает факт оплаты, чек пробивается при доставке
- **Тип оплаты:** `PaymentType` = `cash` (значение по умолчанию) или `card`
- **Кредиты и настройки:** таблица интеграций (`IntegrationConfigService`) с типами `payment` и `fiscal`, значения по умолчанию в `config/services.php`

## 3. Данные

### 3.1 Платёжные поля заказа

```
orders
├── payment_type: enum('cash','card')
├── is_paid: boolean DEFAULT false
├── held_amount: decimal(10,2) NULL          // Подтверждённый холд
├── captured_amount: decimal(10,2) NULL      // Списано (холд + досписания)
├── refunded_amount: decimal(10,2) NULL      // Возвращено
├── surcharge_amount: decimal(10,2) NULL     // Неуспешная доплата при сборке
├── surcharge_expires_at: datetime NULL      // Срок ожидания доплаты
└── payment_card_id: bigint FK → payment_cards.id NULL
```

`captured_amount` пересчитывается как сумма успешных capture-транзакций,
сгруппированных по идентификатору платежа ЮKassa: дубли capture одного платежа
схлопываются (берётся максимум по группе), разные платежи (холд и досписание)
складываются.

### 3.2 Модель PaymentCard

```
payment_cards
├── id: bigint PK
├── user_id: bigint FK → users.id
├── payment_method_id: string              // ID способа оплаты в ЮKassa
├── card_type: string(30)                  // Значение из ЮKassa, при отсутствии 'unknown'
├── last_four: string(4)
├── expiry_month: string(2)
├── expiry_year: string(4)
├── is_default: boolean DEFAULT false
├── created_at / updated_at
└── deleted_at: timestamp NULL             // Мягкое удаление
```

Дедупликация при сохранении карты идёт по паре `last_four` + `card_type`
в рамках пользователя: у найденной записи обновляется `payment_method_id`.
Первая карта пользователя сохраняется с `is_default = true`.

### 3.3 Модель PaymentTransaction

```
payment_transactions
├── id: bigint PK
├── order_id: bigint FK → orders.id NULL       // null у привязки карты и marketplace-холда
├── client_order_id: string NULL               // Консолидированный платёж marketplace
├── type: string(20)                           // hold | capture | refund
├── status: string(20)                         // pending | succeeded | canceled
├── amount: decimal(10,2)
├── currency: string(3) DEFAULT 'RUB'
├── yookassa_payment_id: string NULL
├── yookassa_refund_id: string NULL
├── payment_method_id: string NULL
├── metadata: json NULL                        // {extra_charge: true} | {bind_user_id: N}
├── error_message: text NULL
└── decline_reason: string(50) NULL            // Машинный код отказа ЮKassa
```

### 3.4 Модель PaymentReceipt

```
payment_receipts
├── id: bigint PK
├── order_id: bigint FK → orders.id
├── payment_transaction_id: bigint FK NULL
├── provider: string                           // yookassa | atol
├── external_id: string NULL                   // UNIQUE(provider, external_id)
├── yookassa_receipt_id: string NULL
├── type: string                               // payment | refund
├── status: string                             // pending | succeeded | canceled
├── fiscal_storage_number: string NULL         // ФН
├── fiscal_document_number: string NULL        // ФД
├── fiscal_attribute: string NULL              // ФП
├── receipt_url: string NULL                   // Ссылка на чек в ОФД (АТОЛ)
├── notified_at: datetime NULL                 // Пуш «Чек готов» отправлен
├── registered_at: datetime NULL
├── amount: decimal(12,2) DEFAULT 0
├── items: json                                // Единый формат позиций
└── raw: json                                  // Сырой ответ провайдера
```

### 3.5 Enum'ы

```php
enum PaymentType: string        { case Cash = 'cash'; case Card = 'card'; }
enum PaymentTransactionType: string   { case Hold = 'hold'; case Capture = 'capture'; case Refund = 'refund'; }
enum PaymentTransactionStatus: string { case Pending = 'pending'; case Succeeded = 'succeeded'; case Canceled = 'canceled'; }
enum PaymentReceiptType: string { case Payment = 'payment'; case Refund = 'refund'; }
enum ReceiptProvider: string    { case Yookassa = 'yookassa'; case Atol = 'atol'; }
```

## 4. Флоу оплаты картой

### 4.1 Оформление заказа новой картой

1. `POST /v1/orders` с `paymentType: "card"` без `paymentCardId`.
2. Заказ записывается в БД, товары резервируются.
3. Сервер создаёт холд на `total` заказа: `POST /payments` с `capture: false`, `save_payment_method: true`, `confirmation.type = redirect`, ключ идемпотентности `hold-{orderId}`.
4. В ответе клиенту возвращается `confirmationUrl` страницы оплаты ЮKassa.
5. После оплаты ЮKassa шлёт `payment.waiting_for_capture`: сервер проставляет `held_amount`, сохраняет карту пользователю и привязывает её к заказу.

При включённом модуле `draft_orders_until_payment` шаг 2 выполняется в
черновом режиме, см. [4.8](#48-модуль-draft_orders_until_payment-заказ-только-после-оплаты).

### 4.2 Оформление заказа сохранённой картой (pre-authorization)

1. `POST /v1/orders` с `paymentType: "card"` и `paymentCardId`.
2. До создания заказа сервер выполняет синхронный холд по `payment_method_id` карты на сумму до вычета бонусов, с ключом идемпотентности `preauth-{idempotencyKey}` и описанием «Оплата заказа».
3. Отказ банка: заказ в БД не создаётся, товары не резервируются, ответ `422` с `error: "payment_declined"` и машинным кодом `reason`.
4. Успех: заказ создаётся, транзакция холда фиксируется, при синхронном статусе `waiting_for_capture` сразу проставляется `held_amount`, `confirmationUrl` равен null.
5. Если у карты пустой `payment_method_id`, pre-authorization пропускается и создаётся обычный холд с редиректом.

### 4.3 Списание при завершении сборки

Режим определяется фича-флагом `collector_payment` (настройка модуля).

**Флаг выключен (событийный режим).** Списание запускает событие перехода заказа
в статус `assembled` из курьерки при условиях `payment_type = card`,
`held_amount > 0`, `captured_amount` пустой:
- `grand_total <= held_amount`: capture на `grand_total`, остаток холда разблокируется;
- `grand_total > held_amount`: сначала досписание разницы по сохранённой карте (`POST /payments` с `capture: true`), затем capture холда. Неуспешное досписание не отменяет capture: публикуется событие `order_payment_failed`, `is_paid` остаётся как было.

**Флаг включён (синхронный режим).** Событийный capture отключён, списание
инициирует сборщик через `POST /v1/internal/orders/{order}/settle-payment`.
Порядок операций в `settleAssemblyPayment`:
1. Если заказ с оплатой наличными, ответ успешный без обращения в ЮKassa.
2. Если уже списано не меньше запрошенной суммы, ответ успешный (идемпотентность повторного вызова).
3. Доплата разницы `grand_total − held_amount` за вычетом ранее успешных доплат. Отказ: заказ помечается `surcharge_amount` и `surcharge_expires_at`, публикуется `order_surcharge_required`, клиенту отправляется пуш, холд остаётся нетронутым.
4. Если холда не было, вся сумма собрана доплатой: заказ помечается оплаченным, публикуется `order_paid`.
5. Capture холда на `min(held_amount, grand_total − доплаты)`. Отказ: `surcharge_amount` не выставляется, ставится только `surcharge_expires_at` как страховка.
6. Успех: `surcharge_amount` и `surcharge_expires_at` очищаются.

Срок ожидания доплаты берётся из настройки `surcharge_wait_hours` (по умолчанию
24 часа). Команда `orders:cancel-expired-surcharge` (расписание: каждую минуту)
отменяет заказы в статусе `assembling` с истёкшим сроком: возвращает доплату,
снимает холд, возвращает сток и бонусы, публикует `order_cancelled`.

### 4.4 Отмена заказа и возврат

`refundOrReleaseHold` вызывается при клиентской отмене, внешней отмене из курьерки
и автоотмене заказа самовывоза:
- для заказа с оплатой наличными ничего не делает;
- возвращает разницу `captured_amount − refunded_amount`, если она больше 0.01;
- снимает холд, если он не закрыт capture по тому же идентификатору платежа.

### 4.5 Частичный возврат при доставке

При переходе карточного заказа в `delivered` с непустым `captured_amount` сервер
сравнивает списанную сумму с `grand_total` из события доставки и возвращает
разницу, если она больше 0.01. Для sub-заказов marketplace правило не применяется.

### 4.6 Привязка карты отдельным запросом

1. `POST /v1/payment-cards/bind` создаёт холд на 1 ₽ с описанием «Привязка карты», чеком «Привязка банковской карты» и транзакцией без `order_id` (в `metadata` записывается `bind_user_id`).
2. Клиент оплачивает 1 ₽ по ссылке `confirmationUrl`.
3. По вебхуку `payment.waiting_for_capture` сервер сохраняет карту пользователю, снимает холд 1 ₽ и переводит транзакцию в `canceled`. Списания денег не происходит.

### 4.7 Оплата наличными

Холд и capture не выполняются. При переходе заказа в `delivered` сервер
проставляет `is_paid = true` и пробивает чек на `grand_total` из события доставки.
Блок целиком гейтится наличием активной интеграции `fiscal`: без неё чек не
пробивается и `is_paid` не меняется.

### 4.8 Модуль draft_orders_until_payment (заказ только после оплаты)

Модуль project_modules, по умолчанию выключен. Меняет только флоу [4.1](#41-оформление-заказа-новой-картой)
(оплата картой без `paymentCardId`): наличные и сохранённая карта не затрагиваются,
sub-order'ы marketplace тоже (они создаются с `paymentType = cash`, холд консолидированный).

**Зачем.** Без модуля заказ оформляется до оплаты: корзина очищается, промокод
списывается, заказ уезжает в курьерку — и всё это остаётся, если клиент закрыл
страницу ЮKassa не заплатив. Холд при этом висит `pending` до протухания на
стороне ЮKassa (около часа), а товар всё это время списан с остатка.

**Что меняется при оформлении.** Заказ создаётся в статусе `draft`:

| Действие | Без модуля | Черновик |
|---|---|---|
| Резерв товара, холд бонусов, снапшоты позиций и адреса | да | да |
| Очистка корзины и промокода корзины | да | нет |
| Списание попытки промокода (`promocode_usages`, `used_count`) | да | нет |
| Событие `order.created` в платформу | да | нет |
| Видимость в `GET /orders`, `GET /orders/active` | да | нет |

Ответ ручки прежний: `{"orderId": int, "confirmationUrl": string}`.
Заполняется `orders.awaiting_payment_until` — дедлайн ожидания оплаты
(настройка `draft_order_ttl_minutes`, по умолчанию 15 минут), и
`orders.draft_cart_item_ids` — позиции корзины, вошедшие в черновик.

**Подтверждение оплаты** (`payment.waiting_for_capture`, поллинг клиента или
крон — что раньше) переводит черновик в `pending` и доделывает отложенное:
удаляет из корзины позиции черновика и промокод магазина, списывает попытку
промокода, публикует `order.created`. Операция идемпотентна: заказ берётся
`lockForUpdate`, повторные вызовы выходят на проверке статуса.

Публикуется именно штатное `order.created` (а не кастомное `order_created`):
курьерка слушает первое.

**Неподтверждённая оплата** — `payment.canceled` от ЮKassa либо истёкший
`awaiting_payment_until` (команда `orders:cancel-unpaid`, ежеминутно). Черновик
переводится в `cancelled`, товар возвращается в остаток, резерв бонусов
освобождается, холд снимается best-effort. Событие отмены в платформу **не**
публикуется — она о заказе не знала. Корзина клиента остаётся нетронутой.
Поле `awaiting_payment_until` намеренно не обнуляется: это маркер «заказ умер
черновиком», по нему такие заказы скрыты из истории клиента.

Перед отменой по TTL состояние платежа сверяется с ЮKassa (`GET /payments/{id}`):
если холд подтверждён, а вебхук не доехал — заказ финализируется, а не отменяется.
При недоступном шлюзе черновик остаётся ждать следующего прогона.

`POST /orders/{order}/pay` для черновика продлевает `awaiting_payment_until`
на полный срок ожидания.

## 5. Эндпоинты

Все маршруты с префиксом `v1/`. Доступ к заказу проверяется политикой: заказ
должен принадлежать текущему пользователю, иначе `403`.

### 5.1 POST /orders (платёжная часть)

Полный контракт создания заказа: [`06_orders.md`](./06_orders.md).

| Поле запроса | Правила |
|---|---|
| `paymentType` | `sometimes`, `string`, `in:cash,card`. Отсутствие поля означает `cash` |
| `paymentMethod` | Алиас (новое): при отсутствии `paymentType` значение копируется в `paymentType` до валидации |
| `paymentCardId` | `nullable`, `integer`, карта существует и принадлежит текущему пользователю |

Ответ `201`: `{"orderId": int, "confirmationUrl": string|null}`.

| Условие | confirmationUrl |
|---|---|
| `cash` | null |
| `card` без `paymentCardId` | URL страницы оплаты ЮKassa |
| `card` с `paymentCardId` | null |
| `card` с `paymentCardId`, у карты пустой `payment_method_id` | URL страницы оплаты ЮKassa |
| Повтор с использованным `idempotencyKey` | null, `orderId` ранее созданного заказа |

Ответ `422` при отказе банка на pre-authorization:

```json
{
  "error": "payment_declined",
  "reason": "insufficient_funds",
  "message": "Недостаточно средств на карте"
}
```

`reason` может быть null, если ЮKassa не прислала причину.

### 5.2 POST /orders/{order}/pay

Повторный холд по заказу.

| | |
|---|---|
| Auth | `auth:sanctum`, только владелец заказа |
| Throttle | 5 запросов в минуту |
| Тело запроса | пустое, валидации нет |

Ответ `200`: `{"confirmationUrl": string|null}`. Значение null означает холд по
сохранённой карте заказа без редиректа.

Ответ `422`: `{"message": string, "reason": string|null}`. `reason` (новое)
заполняется при синхронном отказе банка, `message` при отсутствии текста от
`PaymentService` равен `Не удалось создать платёж`.

Поведение: создаётся холд на `total` заказа, при наличии `payment_card_id` по
`payment_method_id` этой карты; ключ идемпотентности запроса в ЮKassa
`hold-{orderId}`; тип оплаты заказа не проверяется; каждая попытка пишет
транзакцию `hold` со статусом `pending` или `canceled`.

### 5.2.1 GET /orders/{order}/payment-status (новое)

Поллинг состояния оплаты после возврата клиента со страницы ЮKassa.

| | |
|---|---|
| Auth | `auth:sanctum`, только владелец заказа |
| Throttle | 60 запросов в минуту |

Для заказа в статусе `draft` состояние платежа сверяется с ЮKassa и, если холд
подтверждён, заказ финализируется прямо в этом запросе — клиент, вернувшийся
раньше вебхука, сразу получает оформленный заказ.

Ответ `200`:

```json
{
  "orderId": 45,
  "status": "pending",
  "paymentState": "confirmed",
  "isPaid": false,
  "awaitingPaymentUntil": null
}
```

| `paymentState` | Значение |
|---|---|
| `awaiting` | оплата не подтверждена, заказ остаётся черновиком; `awaitingPaymentUntil` — дедлайн ожидания |
| `confirmed` | холд подтверждён, заказ оформлен |
| `failed` | оплата не состоялась, черновик закрыт, товары возвращены, корзина клиента сохранена |
| `cancelled` | заказ был оформлен и отменён уже после оплаты |

### 5.3 POST /orders/{order}/refund

| | |
|---|---|
| Auth | `auth:sanctum`, только владелец заказа |
| Throttle | 5 запросов в минуту |
| Тело запроса | `{"amount": number}`, обязательное, `min:0.01` |

Ответ `200`: `{"success": true, "message": "Возврат создан"}`.

Ответ `422`, `{"message": ...}`:

| Условие | message |
|---|---|
| Сумма больше остатка | `Максимальная сумма возврата: {captured − refunded}` (без фиксированных двух знаков) |
| Нет успешных списаний | `Нет успешного списания для возврата` |
| Все платежи уже возвращены | `Нет доступных платежей для возврата` |
| Ошибка ЮKassa | текст `description` из ответа или `Ошибка возврата` |
| `amount` не передан | `Укажите сумму возврата` |
| `amount` меньше 0.01 | `Сумма возврата должна быть больше 0` |

Поведение: сумма распределяется по успешным capture-транзакциям в порядке их
создания (холд, затем досписания), на каждый платёж создаётся отдельный запрос
возврата в ЮKassa с чеком; транзакции пишутся со статусом `pending`;
`refunded_amount` увеличивается только по вебхуку `refund.succeeded`.

### 5.4 GET /orders/{order}/receipts (новое)

Список фискальных чеков заказа: приходы и возвраты.

| | |
|---|---|
| Auth | `auth:sanctum`, только владелец заказа |
| Throttle | отдельного throttle нет |

Ответ `200`: `{"data": [Receipt, ...]}`, сортировка по `registered_at` и `id`
по убыванию, пустой массив при отсутствии чеков.

```
Receipt {
  type: "payment" | "refund"
  status: "pending" | "succeeded" | "canceled"
  registered_at: string|null        # ISO 8601
  total: string                     # "700.00"
  items: ReceiptItem[]
  vat: VatSummary[]                 # свод НДС по ставкам
  fiscal: {fn, fd, fp} | null       # null пока чек не пробит
  receiptUrl: string|null           # ссылка на чек в ОФД (АТОЛ), null для ЮKassa
  qr: string|null                   # строка 54-ФЗ, null пока чек не пробит
  seller: {name: string|null, inn: string|null}
}

ReceiptItem {
  description: string
  quantity: string                  # "1.00"
  amount: string                    # цена за единицу, "600.00"
  vat_code: number                  # 1 без НДС, 2 = 0%, 3 = 10%, 4 = 20%, 5 = 10/110, 6 = 20/120
  vat_rate: string                  # "20" | "10" | "0"
  vat_amount: string                # НДС в позиции, включённый в цену
}

VatSummary {
  vat_code: number
  rate: string                      # "20"
  amount: string                    # суммарный НДС по ставке в чеке
}
```

`fiscal` заполняется, когда статус чека `succeeded` и есть номер фискального
накопителя. `vat_amount` считается сервером из суммы позиции (`amount × quantity`)
по включённой в цену ставке, `vat` группирует эти суммы по коду ставки. `seller`
берётся из настроек АТОЛ, если активен драйвер `atol` и реквизиты заполнены,
иначе из `services.yookassa.seller`.

Позиции хранятся в едином формате для обоих провайдеров. Чеки ЮKassa всегда
содержат позиции с `quantity: "1.00"` и полной суммой позиции в `amount`. Чеки
АТОЛ сохраняют фактическое количество и цену за единицу, но позиция схлопывается
в `quantity: "1.00"` с полной суммой в `amount`, когда произведение цены на
количество не совпадает с суммой позиции до копейки (весовые товары).

Перед ответом сервер досинхронизирует чеки: по успешным транзакциям без чека
тянет данные из ЮKassa, чеки АТОЛ в статусе `pending` опрашивает синхронно
(best-effort, сбой опроса не роняет ответ).

### 5.5 GET /payment-cards

Auth: `auth:sanctum`. Ответ `200`:

```json
{
  "data": [
    {"id": 1, "cardType": "MasterCard", "lastFour": "4444", "expiryMonth": "12", "expiryYear": "2028", "isDefault": true}
  ]
}
```

Сортировка: сначала карта по умолчанию, затем по дате создания по убыванию.

### 5.6 DELETE /payment-cards/{paymentCard}

Auth: `auth:sanctum`. Чужая карта: `403`. Ответ `204 No Content`, удаление мягкое.

### 5.7 POST /payment-cards/bind

Auth: `auth:sanctum`, отдельного throttle нет, тело запроса пустое.

Ответ `200`: `{"confirmationUrl": string|null}` (значение из ответа ЮKassa как есть).
Ответ `422`: `{"message": "Не удалось привязать карту"}` либо текст ошибки ЮKassa.

### 5.8 Платёжные поля в ответах заказов

| Поле | Тип | Описание |
|---|---|---|
| `isPaid` | bool | Признак оплаты |
| `paymentType` | string | `cash` или `card` |
| `heldAmount` | float, может быть null | Подтверждённый холд |
| `capturedAmount` | float, может быть null | Списано, включая досписания |
| `refundedAmount` | float, может быть null | Возвращено |
| `surchargeAmount` | float, может быть null (новое) | Сумма неуспешной доплаты, заказ ожидает доплату |
| `paymentDeclineReason` | string, может быть null (новое) | Код причины последнего отказа холда; отдаётся в `GET /orders`, `GET /orders/{id}`, `GET /orders/active`, `POST /orders/{id}/cancel` |
| `paymentCard` | объект | Помечен `whenLoaded`; связь не подгружается ни одной ручкой заказов, поэтому поле в ответе отсутствует |

## 6. Вебхуки и внутренние ручки (server-to-server)

Мобильный клиент эти ручки не вызывает.

### 6.1 POST /v1/webhooks/yookassa

Без авторизации, доступ ограничен списком подсетей ЮKassa (`185.71.76.0/27`,
`185.71.77.0/27`, `77.75.153.0/25`, `77.75.156.11`, `77.75.156.35`,
`77.75.154.128/25`, `2a02:5180::/32`). В окружениях `local` и `testing` проверка
отключена. Запрос с неизвестного IP: `403`.

| Событие | Действие |
|---|---|
| `payment.waiting_for_capture` | Транзакция холда переводится в `succeeded`, проставляется `held_amount`, карта сохраняется и привязывается к заказу. Для холда привязки карты (без `order_id`) карта сохраняется, холд снимается |
| `payment.succeeded` | Пишется capture-транзакция (идемпотентно по идентификатору платежа), `captured_amount` пересчитывается, `is_paid = true`. При первом учёте платежа публикуется `order_paid` и синхронизируется чек; при драйвере `atol` ставится задача на чек продажи. Заказ с `is_paid = true` пропускается целиком |
| `payment.canceled` | Транзакция переводится в `canceled`, сохраняется `decline_reason` и человекочитаемое сообщение, публикуется `order_payment_failed` |
| `refund.succeeded` | Транзакция возврата переводится в `succeeded`, увеличивается `refunded_amount`, публикуется `order_refunded`; при драйвере `atol` ставится задача на чек возврата, иначе синхронизируется чек ЮKassa |
| Другое | Записывается в лог |

Ответ всегда `200 {"status": "ok"}`.

### 6.2 POST /v1/webhooks/atol

Без авторизации. Тело: `uuid`, `status`, `payload`.

- `status = done`: чек переводится в `succeeded`, заполняются `fiscal_storage_number` (`payload.fn_number`), `fiscal_document_number`, `fiscal_attribute` (`payload.fiscal_document_attribute`), `receipt_url` (`payload.ofd_receipt_url`), `registered_at` (`payload.receipt_datetime` или текущее время), ставится задача пуша «Чек готов»;
- `status = fail`: чек переводится в `canceled`;
- неизвестный `uuid` или уже пробитый чек: изменений нет.

Ответ всегда `200 {"status": "ok"}`.

### 6.3 POST /v1/internal/orders/{order}/settle-payment

Синхронное списание при завершении сборки (модуль `collector_payment`,
вызывается из delivery_back).

| | |
|---|---|
| Middleware | `internal.token`: заголовок `X-Internal-Token` сверяется со значением `INTERNAL_API_TOKEN`, сверка constant-time |
| Пустой сконфигурированный токен | ручка закрыта, ответ `403` |
| Тело запроса | `{"amount": number}`, обязательное, `min:0` |

| HTTP | Тело | Условие |
|---|---|---|
| `200` | `{"status": "ok", "payment_status": "paid"}` | Списание прошло, уже было выполнено ранее или заказ оплачивается наличными |
| `402` | `{"status": "failed", "message": string, "surcharge_amount": float}` | Доплата отклонена, заказ помечен как ожидающий доплату |
| `503` | `{"status": "retry", "message": "Техническая ошибка списания, попробуйте ещё раз"}` | Доплата прошла или не требовалась, capture холда не удался; повторный вызов доплату не дублирует |
| `403` | пусто | Токен отсутствует или не совпадает |

### 6.4 Ручки Яндекс Доставки

Обе без авторизации.

`POST /v1/webhooks/yandex-delivery`: принимает `claim_id`, `status`, `updated_ts`,
`performer_info`, `tracking_url`. Сервер сверяет статус прямым запросом в Яндекс
(при сбое сверки берётся статус из вебхука), обновляет заявку и обрабатывает
переход статуса. Ответ всегда `200 {"status": "ok"}`, включая отсутствующую
заявку и запрос без `claim_id` или `status`.

`POST /v1/internal/yandex-claims/{claimId}/cancel`: отмена заявки из админки.
Ответ `200 {"status": "ok", "claim_status": "..."}`, при ошибке отмены
`500 {"status": "error", "message": "...", "claim_status": "..."}`, при
неизвестной заявке `404`.

## 7. Фискализация (54-ФЗ)

Активный фискализатор выбирается драйвером интеграции `fiscal`: значение `atol`
включает `AtolFiscalizer`, любое другое значение и отсутствие интеграции дают
`YooKassaFiscalizer`.

| Операция | Драйвер `yookassa` | Драйвер `atol` |
|---|---|---|
| Приход по карте | Чек уходит внутри платежа (`receipt` в `POST /payments`), статус тянется из ЮKassa | Отдельный чек `sell` после успешного capture, идемпотентно по наличию чека `payment` у заказа |
| Приход наличными | Независимый чек `POST /receipts` с расчётом типа `cash` | Чек `sell` при доставке |
| Возврат | Чек синхронизируется из ЮKassa по `refund_id` | Чек `sell_refund` на каждый возврат, `external_id` = `{orderId}-refund-{refundId}` |
| `receiptUrl` | null | Ссылка на чек в ОФД |

Когда активен драйвер `atol`, объект `receipt` в запросы ЮKassa не передаётся:
`ReceiptBuilder` возвращает null, чтобы чек не пробивался дважды.

**Настройки чека ЮKassa** (`credentials` интеграции `payment` с приоритетом над
`config/services.php`): `send_receipt` (по умолчанию `true`), `vat_code`
(по умолчанию 4, то есть 20%), `tax_system_code` (значение `none` и пустая строка
означают «не передавать»), `payment_mode` (по умолчанию `full_prepayment`),
`payment_subject` (по умолчанию `commodity`). Ставка НДС берётся по позиции из
снапшота товара в заказе, при отсутствии применяется общая.

**Настройки АТОЛ** (`credentials` интеграции `fiscal`, принимаются и плоской, и
вложенной структурой): `login`, `password`, `group_code`, `mode` (по умолчанию
`test`), `ffd_version` (по умолчанию `1.05`), `inn`, `company_email`,
`payment_address`, `sno`, `vat` (по умолчанию `vat20`), `payment_object`
(по умолчанию `commodity`), `seller_name`.
Версия ФФД `1.05` работает через API v4, версия `1.2` через API v5 (в позиции
добавляется тег `measure`, `payment_object` передаётся числовым кодом). Токен
АТОЛ кешируется на 23 часа отдельно по паре `mode` и `ffd_version`. Адрес
колбэка передаётся в объекте `service.callback_url` и берётся из
`ATOL_CALLBACK_URL` (по умолчанию `{APP_URL}/api/v1/webhooks/atol`).

**Асинхронный статус АТОЛ.** После регистрации чек сохраняется со статусом
`pending`. Статус доводится тремя путями: колбэк `POST /v1/webhooks/atol`,
фоновой опрос `PollAtolReceiptJob` (до 6 попыток с задержками 15, 30, 60, 120,
300, 600 секунд) и синхронный опрос при обращении к `GET /orders/{order}/receipts`.

**Пуш «Чек готов».** Отправляется один раз при переходе чека в `succeeded`
(идемпотентность по `notified_at`): заголовок «Чек готов», текст «Фискальный чек
по заказу №{id} готов», `data`: `type: "receipt_ready"`, `order_id`, `action:
"open_order_details"`, `notification_id`. Все значения `data` строковые.

**Инвариант позиций.** Сумма позиций чека равна сумме платежа до копейки: целевая
сумма распределяется по позициям заказа и доставке пропорционально их стоимости,
остаток округления добирается на последнюю позицию, нулевые позиции отбрасываются.

## 8. Консолидированные платежи marketplace

Отдельный code path для client-order'ов (`21_client_orders_marketplace.md`):
один холд на суммарную сумму всех sub-заказов с транзакцией без `order_id`, но с
`client_order_id`; capture распределяет `captured_amount` и `is_paid` по
sub-заказам; отмена снимает консолидированный холд; возврат идёт по последней
успешной capture-транзакции client-order'а. Чек строится по позициям всех
sub-заказов и распределяется на полную сумму.

## 9. Конфигурация

| Ключ | Назначение | Значение по умолчанию |
|---|---|---|
| `services.yookassa.return_url` (`YOOKASSA_RETURN_URL`) | Адрес возврата после оплаты | `/` (в `.env` и `.env.example` стенда указан адрес форка Aladdin, под mendev не обновлён) |
| `services.yookassa.send_receipt` | Отправка чека в ЮKassa | `true` |
| `services.yookassa.vat_code` | Ставка НДС по умолчанию | `4` (20%) |
| `services.yookassa.tax_system_code` | Система налогообложения | не задано, поле не передаётся |
| `services.yookassa.payment_mode` / `payment_subject` | Признаки способа и предмета расчёта | `full_prepayment` / `commodity` |
| `services.yookassa.seller.name` / `.inn` | Реквизиты продавца в чеке | не задано |
| `services.atol.base_url_test` / `base_url_live` | API v4 (ФФД 1.05) | `https://testonline.atol.ru/possystem/v4`, `https://online.atol.ru/possystem/v4` |
| `services.atol.base_url_test_v5` / `base_url_live_v5` | API v5 (ФФД 1.2) | `https://testonline.atol.ru/possystem/v5`, `https://online.atol.ru/possystem/v5` |
| `services.atol.callback_url` | Адрес колбэка АТОЛ | `{APP_URL}/api/v1/webhooks/atol` |
| `services.atol.timeout` | Таймаут запросов к АТОЛ | 15 секунд |
| `services.internal_api.token` (`INTERNAL_API_TOKEN`) | Токен внутренних ручек | не задано, ручка закрыта |
| Настройка модуля `collector_payment` | Синхронное списание сборщиком | выключено |
| Настройка `surcharge_wait_hours` | Срок ожидания доплаты | 24 часа |

Кредиты ЮKassa (`shop_id`, `secret_key`) берутся из интеграции `payment`:
режим `mode` = `test` или `live` выбирает пару `test_*` или `live_*`.
Таймаут запросов к ЮKassa: 15 секунд.

## 10. Связанные документы

- [`docs/api-payment.md`](../api-payment.md): краткий контракт платёжных ручек для мобильной разработки
- [`2026-07-09-fiscal-receipts-mobile-api-contract.md`](./2026-07-09-fiscal-receipts-mobile-api-contract.md): контракт фискальных чеков и пуша «Чек готов»
- [`06_orders.md`](./06_orders.md): создание заказа и его жизненный цикл
- [`21_client_orders_marketplace.md`](./21_client_orders_marketplace.md): marketplace-режим и консолидированная оплата
- [`17_rabbitmq.md`](./17_rabbitmq.md): события `order_paid`, `order_payment_failed`, `order_refunded`, `order_surcharge_required`
