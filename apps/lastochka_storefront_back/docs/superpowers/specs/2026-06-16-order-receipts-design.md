# Чеки заказа в клиентском приложении (ЮKassa Receipts)

> Дата: 2026-06-16
> Модуль: `aladdin_storefront_back`
> Связано: tg-14118 (отправка `receipt` в платежи, 54-ФЗ, `ReceiptBuilder`)

## Цель

Дать покупателю в мобильном приложении доступ к фискальным чекам его заказа: список всех
чеков (приходы и возвраты) с позициями, суммами, ставками НДС, фискальными реквизитами и
QR-кодом для проверки чека в приложении ФНС.

## Контекст (как есть сейчас)

- `YooKassaService` уже **отправляет** объект `receipt` в `POST /payments`, `/capture`,
  `/refunds` (54-ФЗ). Реквизиты чека собирает `ReceiptBuilder` (позиции, НДС, customer.phone/email).
- В каждый чек уже кладётся `customer.phone` + `customer.email`, поэтому ОФД и сейчас сам
  отправляет покупателю чек на телефон/почту. Эта фича — про показ чека **внутри приложения**.
- Получения чеков (`GET /receipts`) в коде **нет**: фискальные реквизиты нигде не хранятся,
  модели/таблицы под чек нет.
- Есть рабочий вебхук `WebhookController::yookassa` с обработкой `payment.succeeded` и
  `refund.succeeded`, IP-верификацией ЮKassa. Хендлеры в `PaymentService`:
  `handlePaymentSucceeded(string $yookassaPaymentId)`,
  `handleRefundSucceeded(string $refundId, string $paymentId, float $amount)`.
- Связь заказ ↔ платёж: таблица `payment_transactions`
  (`order_id`, `type` ∈ {hold, capture, refund}, `status`, `yookassa_payment_id`, `yookassa_refund_id`).

### Чек ЮKassa — это данные, а не файл

`GET /v3/receipts/{id}` возвращает структурированный JSON с фискальными реквизитами и позициями.
PDF/ссылки на печатную форму в API нет. Печатную форму хранит ОФД; покупатель получает её на
телефон/почту. Для приложения достаточно реквизитов (ФН/ФД/ФПД) + позиций + QR-строки 54-ФЗ.
Реквизиты продавца (название, ИНН) в ответе ЮKassa отсутствуют — берём из своих настроек.

## Контракт API

```
GET /api/v1/orders/{order}/receipts
  auth:sanctum
  authorization: OrderPolicy::view (заказ принадлежит текущему пользователю), иначе 403
```

Ответ — массив чеков, сортировка новый → старый:

```jsonc
{
  "data": [
    {
      "type": "payment",                       // payment | refund
      "status": "succeeded",                    // succeeded | pending | canceled
      "registered_at": "2026-06-16T15:42:00+03:00",
      "total": "1014.00",
      "items": [
        { "description": "Молоко 3.2% 1л", "quantity": "1.00", "amount": "600.00", "vat_code": 4, "vat_rate": "20", "vat_amount": "100.00" },
        { "description": "Доставка",       "quantity": "1.00", "amount": "414.00", "vat_code": 4, "vat_rate": "20", "vat_amount": "69.00" }
      ],
      "vat": [
        { "vat_code": 4, "rate": "20", "amount": "169.00" }
      ],
      "fiscal": { "fn": "9288000100115785", "fd": "3986", "fp": "2617603921" },
      "qr": "t=20260616T1542&s=1014.00&fn=9288000100115785&i=3986&fp=2617603921&n=1",
      "seller": { "name": "ООО Ромашка", "inn": "7700000000" }
    }
  ]
}
```

Сериализация: `OrderReceiptResource`.

Правила полей:
- `fiscal` и `qr` заполнены только при `status = succeeded` (касса пробила, реквизиты есть).
  При `status = pending`/`canceled` оба ключа присутствуют в ответе со значением `null`
  (ключи всегда есть — стабильный контракт для фронта), а `items`/`total` отдаются.
- `seller` — из настроек магазина, не из ЮKassa.
- НДС: ЮKassa в `GET /receipts` отдаёт только код ставки `vat_code` (1 — без НДС, 2 — 0%,
  3 — 10%, 4 — 20%, 5 — 10/110, 6 — 20/120) без суммы налога. Сумму считаем сами, НДС включён
  в цену («в том числе»): `vat_amount = amount × rate / (100 + rate)`. В каждой позиции
  добавлены `vat_rate` (проценты строкой) и `vat_amount` (рубли строкой). Блок `vat` —
  свод по чеку, сгруппированный по ставке (как строка «СУММА НДС» фискального чека);
  сумма группы = сумма НДС её позиций.

## Хранение — таблица `payment_receipts`

Чеков на заказ несколько, каждый привязан к конкретной транзакции → отдельная таблица
(не JSON-поля в `payment_transactions`).

```
payment_receipts
  id                       bigint pk
  order_id                 fk -> orders
  payment_transaction_id   fk -> payment_transactions (nullable)
  yookassa_receipt_id      string  (unique)
  type                     enum(payment, refund)
  status                   enum(pending, succeeded, canceled)
  fiscal_storage_number    string  nullable   // fn
  fiscal_document_number   string  nullable   // fd
  fiscal_attribute         string  nullable   // fp
  registered_at            datetime nullable
  amount                   decimal(12,2)
  items                    json                // нормализованные позиции для выдачи
  raw                      json                // полный ответ ЮKassa (на случай разбора)
  created_at / updated_at
  unique(yookassa_receipt_id)
```

Модель `PaymentReceipt`: `belongsTo Order`, `belongsTo PaymentTransaction`.
Касты: `status`/`type` — enum, `items`/`raw` — array/json, `registered_at` — datetime, `amount` — decimal:2.

## Поток получения — webhook-driven + lazy fallback

Выбор стратегии: данные тянем по вебхуку и храним у себя (а не дёргаем ЮKassa на каждый
клиентский запрос), потому что вебхук уже есть — клиент читает быстро из нашей БД, данные
переживают недоступность ЮKassa и пригодны для будущей админки/бухгалтерии.

### Сохранение (основное, по вебхуку)
1. `handlePaymentSucceeded($yookassaPaymentId)` и `handleRefundSucceeded($refundId, …)` после
   своей текущей логики вызывают `ReceiptSyncService::syncForTransaction(PaymentTransaction)`.
2. `ReceiptSyncService` зовёт `YooKassaService::getReceipts(paymentId|refundId)`.
3. Каждый чек из ответа → upsert в `payment_receipts` по `yookassa_receipt_id` (идемпотентно).

### Чтение клиентом
1. Контроллер находит заказ, проверяет владельца (policy).
2. Собирает все `PaymentTransaction` заказа (hold/capture, возможный досписной платёж, refund).
3. Отдаёт связанные `payment_receipts` из нашей БД.

### Fallback
Если у транзакции `status = succeeded`, а чека в `payment_receipts` ещё нет (вебхук опоздал/сбой) —
контроллер синхронно вызывает `ReceiptSyncService::syncForTransaction` перед ответом.

### Новый метод гейтвея
`YooKassaService::getReceipts(?string $paymentId, ?string $refundId): array`
- `GET /v3/receipts?payment_id=…` либо `?refund_id=…` (взаимоисключающие фильтры).
- Пагинация по `cursor` (собрать все страницы).
- На ошибку — лог + пустой массив (graceful, как остальные методы сервиса).

## Сборка QR и продавца

- `ReceiptQrBuilder::build(PaymentReceipt): ?string` — строка стандарта 54-ФЗ
  `t=<registered_at YYYYMMDDTHHMM>&s=<amount>&fn=<fn>&i=<fd>&fp=<fp>&n=<1|2>`,
  где `n=1` для `type=payment` (приход), `n=2` для `type=refund` (возврат прихода).
  Возвращает `null`, если `status != succeeded` или реквизиты пусты.
- `seller` (name, inn) — из настроек магазина (`integration_configs`/`config/services.yookassa`),
  параметризуемо под форк.

## Edge cases

| Ситуация | Поведение |
|---|---|
| Чек `pending` (касса не пробила) | элемент массива со `status:pending`, без `fiscal`/`qr` |
| Касса не подключена / `/receipts` пуст | `data: []` |
| Заказ без онлайн-оплаты (оплата при получении) | `data: []` |
| Оплата + досписание + возврат | несколько элементов массива |
| Чужой заказ | 403 (OrderPolicy) |

## Тесты

### Unit
- `ReceiptQrBuilder`: формат строки; `n=1` для payment, `n=2` для refund; `null` при pending;
  `null` при отсутствии fiscal-реквизитов.
- `ReceiptSyncService`: маппинг ответа ЮKassa → `PaymentReceipt`; идемпотентность upsert по
  `yookassa_receipt_id`; обработка пустого ответа.
- `YooKassaService::getReceipts`: сбор страниц по `cursor`; пустой массив при HTTP-ошибке
  (через `Http::fake`).

### Feature
- `GET /orders/{id}/receipts`: успех (Http::fake ЮKassa) → массив с позициями + fiscal + qr.
- pending-чек → элемент без fiscal/qr.
- чужой заказ → 403.
- fallback-синк: чека в БД нет, транзакция succeeded → синхронный запрос к ЮKassa наполняет ответ.
- заказ с payment + refund → два элемента, корректные `type`/`n`.

## Файлы (ориентировочно)

Новые:
- `database/migrations/XXXX_create_payment_receipts_table.php`
- `app/Models/PaymentReceipt.php`
- `app/Services/Payment/ReceiptSyncService.php`
- `app/Services/Payment/ReceiptQrBuilder.php`
- `app/Http/Controllers/Api/V1/OrderReceiptController.php`
- `app/Http/Resources/Api/V1/OrderReceiptResource.php`
- тесты (unit + feature по списку выше)

Изменяемые:
- `app/Services/Payment/YooKassaService.php` — метод `getReceipts`.
- `app/Services/Payment/PaymentService.php` — вызовы `ReceiptSyncService` в `handlePaymentSucceeded` /
  `handleRefundSucceeded`.
- `routes/api.php` — маршрут `GET v1/orders/{order}/receipts`.
- `app/Policies/OrderPolicy.php` — переиспользовать `view` (если нет — добавить).

## Раскатка на форки

Фича generic (нужна всем форкам с YooKassa: lastochka/electrohub/mendev/cron). Реквизиты
продавца и `vat_code`/СНО — параметризовать через настройки, не хардкодить. Раскатывать через
sync-core по команде.
