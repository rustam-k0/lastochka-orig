# Фискализация чеков (54-ФЗ) с выбором драйвера: АТОЛ Онлайн / ЮKassa

- **Задача:** Platrum LASTOCHKI-JAMI-11651 «Фискальный чек через АТОЛ по API (нал + онлайн) в приложение»
- **Дата:** 2026-07-09
- **Модули:** `lastochka_storefront_back` (ядро фискализации), `lastochka_docker/lastochka_admin_back` + `lastochka_admin_front` (настройки), `lastochka_client_mobile` (показ чека + пуш — контракт API)
- **Не затрагивается:** `lastochka_delivery_back`, приложение курьера `lastochka_djami_employee` (наличные фискализируются по существующему событию «Доставлен»)

## Цель

Пробивать фискальный чек по 54-ФЗ для онлайн-оплаты и оплаты наличными при получении, с выбором фискализатора (АТОЛ или ЮKassa) через настройки в админке. Чек доступен клиенту в приложении, отправляется пуш «Чек готов». Исключить двойной чек и потерю чека при ошибке (ретраи через очередь + Sentry).

## Ключевые решения (согласовано)

1. **Фискализатор отделён от эквайринга.** Эквайринг (приём онлайн-денег) — всегда ЮKassa. Фискализатор — выбирается независимо (`atol` / `yookassa`).
2. **Оба драйвера покрывают оба способа оплаты** (онлайн + наличные) — полный 54-ФЗ.
3. **Наличные фискализируются по событию «Доставлен»** из курьерки (уже прилетает в storefront). Курьерский бэкенд и приложение курьера не меняются.
4. **Один чек по онлайну** — в момент оплаты; второй чек при доставке онлайн-заказа не бьётся (наследуем текущую семантику ЮKassa `payment_mode`).
5. **Асинхронная регистрация** чеков через очередь с ретраями и логированием ошибок в Sentry.
6. Область — заказы клиентского приложения (проходящие через платёжный/чековый контур storefront). Заказы, заведённые напрямую в курьерке, в этой задаче не фискализируются.

## Текущее состояние (before)

- Фискализация 54-ФЗ реализована **только для онлайн-оплаты и только через ЮKassa**: чек (`ReceiptData`) собирается `ReceiptBuilder` и уходит внутри `POST /payments` (и `/capture`, `/refunds`); реквизиты подтягиваются обратно `ReceiptSyncService` в таблицу `payment_receipts`.
- `PaymentGateway` жёстко биндится на `YooKassaService` (`AppServiceProvider`). Поле `driver` в `integration_configs` уже есть, но фабрики по драйверу нет.
- Хранение: `payment_receipts` (ФН/ФД/ФП, `yookassa_receipt_id` unique NOT NULL, `items`, `raw`). API: `GET /v1/orders/{order}/receipts` → `OrderReceiptResource` (`fiscal{fn,fd,fp}`, `qr` через `ReceiptQrBuilder`, `seller` из `config('services.yookassa.seller')`).
- **Наличные не фискализируются нигде.** В курьерке (`DeliveryFinish`) нет отметки об оплате — только смена статуса на «Доставлен»; `paid` не пишется, в `order.updated` уходит прежнее значение `is_paid`.
- Механизм «драйвер интеграции» (реестр/дескрипторы/тестеры в `admin_back`, generic-UI + кнопка «Проверить подключение» в `admin_front`, доставка конфига в storefront по RabbitMQ) — готов, тип `payment`/драйвер `yookassa` присутствует.

## Архитектура

### 1. Контракт `Fiscalizer` (storefront_back)

`app/Contracts/Fiscalizer.php`:

```php
interface Fiscalizer
{
    // Чек прихода. Возвращает результат регистрации (uuid/pending или сразу реквизиты).
    public function fiscalizeSale(Order $order, PaymentType $paymentType, float $amount): FiscalReceiptResult;

    // Чек возврата прихода.
    public function fiscalizeRefund(Order $order, float $amount): FiscalReceiptResult;
}
```

Реализации:

- **`AtolFiscalizer`** (`app/Services/Payment/Fiscal/AtolFiscalizer.php`) — пробивает чек через API АТОЛ Онлайн:
  `getToken` (кэш токена в Redis ~23 ч) → `POST /{group_code}/sell` (или `/sell_refund`) с `external_id` (идемпотентный ключ) → `uuid` → результат по callback или поллингу → ФН/ФД/ФП + `ofd_receipt_url`.
- **`YooKassaFiscalizer`** (`app/Services/Payment/Fiscal/YooKassaFiscalizer.php`) — обёртка над текущим поведением ЮKassa:
  - онлайн — чек регистрируется силами ЮKassa внутри платежа (как сейчас), реквизиты через `ReceiptSyncService`;
  - наличные — отдельный «чек без движения денег» через `POST /receipts` ЮKassa (`type: payment`, `settlements` с наличным расчётом).

`FiscalReceiptResult` (DTO) — `provider`, `externalId`, `status` (pending|succeeded|canceled), опц. `fn/fd/fp`, `receiptUrl`, `raw`.

### 2. Фабрика по драйверу

`AppServiceProvider`: биндинг `Fiscalizer::class` через фабрику, читающую `IntegrationConfigService::getDriver('fiscal')` (`atol` → `AtolFiscalizer`, `yookassa`/по умолчанию → `YooKassaFiscalizer`). `PaymentGateway → YooKassaService` остаётся неизменным (эквайринг). В юнит-тестах — `FakeFiscalizer`.

### 3. Инвариант «нет двойного чека»

Точка развилки — онлайн-оплата в `PaymentService`/`ReceiptBuilder`:

- фискализатор `yookassa` → `receipt` кладётся в `POST /payments` (текущее поведение 1:1);
- фискализатор `atol` → ЮKassa-платёж создаётся **без** `receipt` (`ReceiptBuilder` при активном драйвере `atol` возвращает `null`); после `payment.succeeded` асинхронно вызывается `AtolFiscalizer::fiscalizeSale`.

Один чек, из одного источника.

## Настройки в админке

### admin_back — дескриптор `fiscal`

`app/Integrations/Descriptors/FiscalIntegrationDescriptor.php`, регистрируется в `IntegrationRegistry` как тип `fiscal` («Фискализация»).

**Драйвер `atol`** — `credentials_schema`:

| field | type | назначение |
|---|---|---|
| `mode` | select (test/live) | контур АТОЛ (тест/бой) |
| `login` | text | логин API АТОЛ |
| `password` | password | пароль API АТОЛ |
| `group_code` | text | код группы касс |
| `inn` | text | ИНН продавца (`company.inn`) |
| `payment_address` | text | адрес расчётов (`company.payment_address`) |
| `sno` | select | система налогообложения (осн/усн_доход/усн_доход_расход/есн/патент) |
| `vat` | select | ставка НДС по умолчанию (без НДС/0/10/20) |
| `payment_object` | select | признак предмета расчёта по умолчанию |
| `seller_name` | text | имя продавца для отображения чека |

**Драйвер `yookassa`** — фискальные поля, перенесённые из `PaymentIntegrationDescriptor`: `send_receipt`, `vat_code`, `tax_system_code`, `payment_subject`, `payment_mode`, реквизиты продавца. В типе `payment` остаётся чистый эквайринг (`mode`, `shop_id`/`secret_key`).

### Тестеры (`app/Integrations/Testers/`)

- **`AtolTester`** — `getToken` по логину/паролю; при успехе — проверка доступности `group_code`. `{success, message}`.
- **`YooKassaFiscalTester`** — проверяет (по требованию из комментария к задаче):
  1. интеграция `payment` с драйвером ЮKassa **активна**;
  2. стоит **боевой режим** (`mode=live`);
  3. **фискализация включена** — `GET /v3/me`, флаг `fiscalization_enabled`.
  При провале — конкретное сообщение («Подключите оплату ЮKassa» / «Включите боевой режим» / «Фискализация не активирована в кассе ЮKassa»).

### admin_front

Правок по коду нет — `IntegrationConfigModal` рисует поля и кнопку «Проверить подключение» динамически по дескриптору; карточка «Фискализация» появляется на `/admin/settings/integrations` автоматически. Опционально — подсказка-ссылка на инструкцию для типа `fiscal` (как уже сделано для `payment`).

### Доставка в storefront_back

Через существующий RabbitMQ-канал `integration_configs` (тип `fiscal` — platform-wide, проходит `PlatformWideIntegrationCondition`). В storefront — `IntegrationConfigService::get('fiscal')`.

## Флоу фискализации

### Онлайн-оплата (карта через ЮKassa)

- драйвер `yookassa` — без изменений (чек внутри платежа, `ReceiptSyncService` по `payment.succeeded`);
- драйвер `atol` — в webhook `payment.succeeded` (после `is_paid=true`) диспатчится `FiscalizeSaleJob(order, amount, Card)` → `sell` с `payments.type = электронный`. Один чек в момент оплаты.

### Наличные (оплата курьеру)

- триггер — входящее `order.updated` со статусом `delivered` в `OrderEventTransformer::fromEvent` (storefront);
- условие: `payment_type == cash` && статус стал `delivered` && нет успешного чека прихода по заказу (идемпотентность);
- действие: `is_paid=true` + `FiscalizeSaleJob(order, deliveredAmount, Cash)`;
- сумма — фактически доставленная (`grand_total` события, с учётом возвратов курьера); при доставке с полным возвратом (0) чек не бьётся;
- любой драйвер: `atol` → `sell` с наличным расчётом; `yookassa` → «чек без платежа» через `POST /receipts`.

### АТОЛ — механика Job

- `FiscalizeSaleJob` / `FiscalizeRefundJob`: `getToken` (кэш) → `sell`/`sell_refund` с `external_id = {order_id}-{operation}` → `uuid`, запись `payment_receipts` в статусе `pending`;
- результат — по callback (`POST /v1/webhooks/atol`, сверка `uuid`) **или** резервный поллинг `PollAtolReceiptJob` (`GET /report/{uuid}`, delayed-ретраи); берётся тот, что раньше;
- `status=done` → ФН/ФД/ФП + `ofd_receipt_url`, статус `succeeded`; `status=fail` → `canceled`, `raw` с ошибкой, Sentry;
- ретраи: `backoff` (10s/60s/300s), ограниченный `tries`; после исчерпания — Sentry + запись остаётся для ручного разбора;
- идемпотентность — `external_id` (повторный `sell` тем же ключом на стороне АТОЛ не задваивает чек).

### Возвраты

- онлайн (`refund.succeeded`): `atol` → `FiscalizeRefundJob` → `sell_refund`; `yookassa` → как сейчас;
- наличный заказ, частичный возврат курьером при доставке: приход бьётся на фактически доставленное — отдельный чек возврата не нужен (деньги за возвращённое не берутся). Сценарий возврата уже доставленного наличного заказа в текущем флоу отсутствует; при появлении — добавить `sell_refund`.

## Хранение

Миграция `payment_receipts` (с `hasColumn`-guard):

- `provider` (`yookassa`/`atol`), `external_id` (nullable — `uuid` АТОЛ / `receipt_id` ЮKassa), `receipt_url` (nullable — ссылка на чек в ОФД);
- `yookassa_receipt_id` → nullable; unique-индекс заменяется на `(provider, external_id)` unique;
- бэкфилл существующих строк: `provider='yookassa'`, `external_id=yookassa_receipt_id`;
- ФН/ФД/ФП/`items`/`raw`/`amount`/`type`/`status` — уже generic.

Модель `PaymentReceipt` — новые поля в `$fillable`; enum `ReceiptProvider`.

## API чеков и мобилка

### Эндпоинт (уже существует)

`GET /v1/orders/{order}/receipts` → `OrderReceiptResource`. Правки:

- `seller` — из настроек активного фискализатора (`IntegrationConfigService::get('fiscal')`), fallback на config;
- добавить `receiptUrl` (`ofd_receipt_url` для АТОЛ; для ЮKassa `null`);
- `qr` — существующий `ReceiptQrBuilder` (провайдеро-независим, из ФН/ФД/ФП/суммы/времени);
- `ensureReceiptsFresh` — для АТОЛ-чеков в `pending` лениво дёргать поллинг статуса.

### Пуш «Чек готов»

При переходе чека в `succeeded` (callback/поллинг АТОЛ, либо sync ЮKassa) и если пуш ещё не отправлялся — FCM-пуш клиенту («Чек готов», payload с `order_id`). По тапу мобилка открывает детали заказа и тянет `/receipts`. Флаг «пуш отправлен» на записи чека — защита от задвоения (callback + поллинг).

### Контракт мобилке (client_mobile — READ-ONLY)

Отдельный документ по API-контракту (скилл `api-contract-handoff`): `GET /v1/orders/{id}/receipts` (`fiscal{fn,fd,fp}`, `qr`, `receiptUrl`, `items`, `vat`, `seller`, `total`, `status`) + формат FCM-пуша «Чек готов».

## Раскатка на форки

Код фискализации — generic платформенный, кандидат на broadcast через sync-core на Aladdin/Electrohub/Foodlink (идентичность форков). Одна feature-ветка на фичу (`feat/LASTOCHKI-JAMI-11651` в каждом затронутом репо), мерж `--no-ff` по явной команде, ветку не удалять. После broadcast — сверить присутствие коммитов на каждом форке (`git log --grep`). После cherry-pick — добавить записи настроек `fiscal` в БД dev-серверов форков (тип `fiscal` в `integration_configs`), иначе фича невидима.

## Тестирование

- **Unit:** фискализаторы (сборка тела АТОЛ `sell`, распределение сумм/НДС, маппинг признаков расчёта и НДС), фабрика по драйверу, тестеры (`AtolTester`, `YooKassaFiscalTester`).
- **Feature:** webhook `payment.succeeded` при `atol` не задваивает чек; `order.updated delivered` бьёт наличный чек идемпотентно; callback АТОЛ пишет реквизиты и шлёт пуш один раз; API `/receipts` отдаёт `receiptUrl`/`seller` из настроек.
- **E2E на dev** (реальные API-пути, не factory): оба драйвера × (онлайн + наличные). Онлайн — `POST /v1/orders` карта → capture → чек; наличные — заказ cash → propagation admin → событие `delivered` → чек. Проверка: `payment_receipts` заполнены, `GET /receipts` отдаёт фискальные реквизиты, пуш ушёл.

## Критерии приёмки (из задачи)

- [x] Онлайн-оплата → один фискальный чек, доступен в приложении.
- [x] Оплата при получении → чек после подтверждения доставки (событие «Доставлен»).
- [x] Нет дублирующих чеков (инвариант выбора драйвера).
- [x] Ошибка фискализации логируется (Sentry) и ретраится через очередь.
- [x] Выбор фискализатора (Атол/ЮKassa) через настройки в админке + кнопка «Проверить подключение».
