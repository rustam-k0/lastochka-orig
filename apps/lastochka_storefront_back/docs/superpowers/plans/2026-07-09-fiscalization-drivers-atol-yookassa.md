# Фискализация чеков (54-ФЗ) с драйверами АТОЛ / ЮKassa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Пробивать фискальный чек 54-ФЗ по онлайн-оплате и оплате наличными при получении, с выбором фискализатора (АТОЛ / ЮKassa) в админке, отдавать чек в приложение и слать пуш «Чек готов».

**Architecture:** Отделяем «фискализатор» от «эквайринга». Эквайринг остаётся ЮKassa. Вводим контракт `Fiscalizer` (storefront_back) с двумя реализациями — `AtolFiscalizer` (бьёт чек через API АТОЛ Онлайн) и `YooKassaFiscalizer` (текущее поведение ЮKassa + чек по налу отдельным запросом). Драйвер выбирается в админке (тип интеграции `fiscal`), доезжает в storefront по RabbitMQ. Регистрация чеков — асинхронно, через очередь с ретраями и Sentry.

**Tech Stack:** PHP 8.4, Laravel 12 (storefront_back); PHP 8.x, Laravel 9 + Lighthouse (admin_back); RabbitMQ (`comrades/laravel-rabbitmq-events`); Firebase Messaging (Kreait); HTTP через `Illuminate\Support\Facades\Http`.

## Global Constraints

- **Не урезать scope** — реализовать оба драйвера × оба способа оплаты целиком.
- **Обратная совместимость фискализации ЮKassa:** фискальные настройки ЮKassa (`send_receipt`, `vat_code`, `tax_system_code`, `payment_subject`, `payment_mode`, `seller`) ОСТАЮТСЯ в `integration_configs` тип `payment` драйвер `yookassa` + `config/services.php`. Тип `fiscal` управляет ТОЛЬКО выбором драйвера и кредами АТОЛ. **(Отступление от спеки: не переносим/не удаляем фискальные поля из `payment` — иначе ломается уже работающая ЮKassa-фискализация.)**
- **Не двойной чек:** при активном фискализаторе `atol` ЮKassa НЕ передаёт `receipt` в платёж.
- **Наличные фискализируются по событию `delivered`** из курьерки, без изменений в `lastochka_delivery_back` и приложении курьера.
- **PHP-строки с кириллицей** (наименования товаров, продавца) — только `mb_*` функции.
- **Ветки:** одна feature-ветка на репо (`feat/LASTOCHKI-JAMI-11651`), коммиты туда, мерж `--no-ff` по явной команде, ветку не удалять. Не коммитить в `main`/`master`.
- **Тесты — только локально.** storefront_back: `/opt/homebrew/opt/php@8.4/bin/php artisan test` (SQLite in-memory). admin_back (docker-модуль): `DB_HOST=127.0.0.1 vendor/bin/phpunit`, файлы по одному. Категорически не запускать на серверах.
- **Коды АТОЛ v4** (сверено): `payments.type` — `0`=наличные, `1`=электронные. `vat.type` — `none|vat0|vat10|vat20|vat110|vat120`. Маппинг из ЮKassa `vat_code`: 1→none, 2→vat0, 3→vat10, 4→vat20, 5→vat110, 6→vat120. `payment_method` (признак способа расчёта): онлайн → `full_prepayment` (как текущий `payment_mode` ЮKassa), нал → `full_payment`. `payment_object`: товар → `commodity`, доставка → `service`.

---

## Файловая структура

### storefront_back (репо `lastochka_storefront_back`, ветка от `main`)

Create:
- `app/Enums/ReceiptProvider.php` — enum провайдера чека (`yookassa`/`atol`).
- `app/Contracts/Fiscalizer.php` — контракт фискализатора.
- `app/DTOs/Payment/FiscalReceiptResult.php` — результат регистрации чека.
- `app/Services/Payment/Fiscal/AtolClient.php` — тонкий HTTP-клиент АТОЛ Онлайн (getToken/sell/sell_refund/report).
- `app/Services/Payment/Fiscal/AtolFiscalizer.php` — реализация `Fiscalizer` для АТОЛ.
- `app/Services/Payment/Fiscal/YooKassaFiscalizer.php` — реализация `Fiscalizer` для ЮKassa.
- `app/Services/Payment/Fiscal/FakeFiscalizer.php` — фейк для тестов.
- `app/Services/Payment/Fiscal/AtolReceiptBuilder.php` — сборка тела `receipt` АТОЛ из `Order`.
- `app/Jobs/FiscalizeSaleJob.php`, `app/Jobs/FiscalizeRefundJob.php`, `app/Jobs/PollAtolReceiptJob.php`.
- `app/Jobs/SendReceiptReadyNotification.php` — пуш «Чек готов».
- `database/migrations/2026_07_09_100000_generalize_payment_receipts_for_providers.php`.
- Тесты в `tests/Unit/Services/Payment/Fiscal/`, `tests/Feature/Api/V1/Fiscal/`.

Modify:
- `app/Providers/AppServiceProvider.php` — фабрика `Fiscalizer`.
- `app/Models/PaymentReceipt.php` — новые поля в `$fillable` + `provider` cast.
- `app/Services/Payment/ReceiptSyncService.php` — `upsert` пишет `provider`/`external_id`, dedup по `(provider, external_id)`.
- `app/Services/Payment/ReceiptBuilder.php` — `sendReceiptEnabled()` учитывает драйвер `fiscal`.
- `app/Services/Payment/PaymentService.php` — после `payment.succeeded`/refund при `atol` диспатч job'ов; `handlePaymentSucceeded` не задваивает.
- `app/RabbitMQ/Transformers/OrderEventTransformer.php` — `afterUpdate`: `delivered` + `cash` → `is_paid` + `FiscalizeSaleJob`.
- `app/Http/Controllers/Api/V1/WebhookController.php` — метод `atol()`.
- `routes/api.php` — роут `v1/webhooks/atol`.
- `app/Http/Resources/Api/V1/OrderReceiptResource.php` — `receiptUrl`, `seller` из настроек `fiscal`.
- `app/Http/Controllers/Api/V1/OrderReceiptController.php` — `ensureReceiptsFresh` поллит АТОЛ-`pending`.
- `config/services.php` — блок `atol`.

### admin_back (репо `lastochka_admin_back`, ветка от `master`)

Create:
- `app/Integrations/Descriptors/FiscalIntegrationDescriptor.php`.
- `app/Integrations/Testers/AtolTester.php`.
- `app/Integrations/Testers/YooKassaFiscalTester.php`.
- Тесты в `tests/Feature/Integrations/`.

Modify:
- `app/Integrations/IntegrationRegistry.php` — регистрация типа `fiscal`.

### admin_front — правок кода НЕТ (карточка «Фискализация» появляется автоматически из реестра).

### Мобилка — документ API-контракта (см. Задача 18), кода не пишем.

---

## Фаза 1 — Ядро фискализации (storefront_back)

### Task 1: Обобщение хранилища чеков `payment_receipts`

**Files:**
- Create: `app/Enums/ReceiptProvider.php`
- Create: `database/migrations/2026_07_09_100000_generalize_payment_receipts_for_providers.php`
- Modify: `app/Models/PaymentReceipt.php`
- Modify: `app/Services/Payment/ReceiptSyncService.php:42-67`
- Test: `tests/Feature/Fiscal/PaymentReceiptGeneralizationTest.php`

**Interfaces:**
- Produces: `App\Enums\ReceiptProvider` (`Yookassa='yookassa'`, `Atol='atol'`); колонки `payment_receipts.provider`, `.external_id`, `.receipt_url`; `PaymentReceipt::$fillable` включает эти поля; dedup чеков по `(provider, external_id)`.

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Fiscal/PaymentReceiptGeneralizationTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Fiscal;
use App\Enums\ReceiptProvider;
use App\Models\Order;
use App\Models\PaymentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentReceiptGeneralizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_stores_provider_external_id_and_url(): void
    {
        $order = Order::factory()->create();
        $receipt = PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol,
            'external_id' => 'atol-uuid-1',
            'type' => 'payment',
            'status' => 'succeeded',
            'receipt_url' => 'https://ofd.example/r/1',
            'amount' => 100.00,
            'items' => [],
            'raw' => [],
        ]);

        $this->assertSame(ReceiptProvider::Atol, $receipt->fresh()->provider);
        $this->assertSame('atol-uuid-1', $receipt->fresh()->external_id);
        $this->assertSame('https://ofd.example/r/1', $receipt->fresh()->receipt_url);
    }

    public function test_provider_and_external_id_are_unique_together(): void
    {
        $order = Order::factory()->create();
        $base = ['order_id' => $order->id, 'provider' => ReceiptProvider::Atol,
            'external_id' => 'dup', 'type' => 'payment', 'status' => 'pending',
            'amount' => 1, 'items' => [], 'raw' => []];
        PaymentReceipt::create($base);
        $this->expectException(\Illuminate\Database\QueryException::class);
        PaymentReceipt::create($base);
    }
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=PaymentReceiptGeneralizationTest`
Expected: FAIL (unknown column `provider` / class `ReceiptProvider` not found).

- [ ] **Step 3: Create enum**

```php
// app/Enums/ReceiptProvider.php
<?php
declare(strict_types=1);
namespace App\Enums;

enum ReceiptProvider: string
{
    case Yookassa = 'yookassa';
    case Atol = 'atol';
}
```

- [ ] **Step 4: Migration (guarded, back-fill existing)**

```php
// database/migrations/2026_07_09_100000_generalize_payment_receipts_for_providers.php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_receipts', 'provider')) {
                $table->string('provider')->default('yookassa')->after('payment_transaction_id');
            }
            if (! Schema::hasColumn('payment_receipts', 'external_id')) {
                $table->string('external_id')->nullable()->after('provider');
            }
            if (! Schema::hasColumn('payment_receipts', 'receipt_url')) {
                $table->string('receipt_url')->nullable()->after('fiscal_attribute');
            }
        });

        // Back-fill: старые строки = yookassa, external_id = yookassa_receipt_id
        DB::table('payment_receipts')
            ->whereNull('external_id')
            ->update(['external_id' => DB::raw('yookassa_receipt_id'), 'provider' => 'yookassa']);

        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->string('yookassa_receipt_id')->nullable()->change();
        });

        // Заменить unique(yookassa_receipt_id) на unique(provider, external_id).
        // Имя индекса по умолчанию: payment_receipts_yookassa_receipt_id_unique
        try {
            Schema::table('payment_receipts', function (Blueprint $table) {
                $table->dropUnique('payment_receipts_yookassa_receipt_id_unique');
            });
        } catch (\Throwable $e) {
            // индекс мог не существовать в тестовой БД — игнорируем
        }
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropUnique(['provider', 'external_id']);
            $table->dropColumn(['provider', 'external_id', 'receipt_url']);
        });
    }
};
```

- [ ] **Step 5: Update model**

В `app/Models/PaymentReceipt.php` добавить в `$fillable` строки `'provider'`, `'external_id'`, `'receipt_url'`; в `casts()` — `'provider' => ReceiptProvider::class`; импорт `use App\Enums\ReceiptProvider;`.

- [ ] **Step 6: Update `ReceiptSyncService::upsert`**

Заменить dedup-ключ и добавить provider/external_id (`app/Services/Payment/ReceiptSyncService.php:49-51`):

```php
        PaymentReceipt::updateOrCreate(
            ['provider' => \App\Enums\ReceiptProvider::Yookassa->value, 'external_id' => $receiptId],
            [
                'order_id' => $transaction->order_id,
                'payment_transaction_id' => $transaction->id,
                'yookassa_receipt_id' => $receiptId,
                'type' => $item['type'] ?? 'payment',
                // ...остальные поля без изменений...
            ],
        );
```

- [ ] **Step 7: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=PaymentReceiptGeneralizationTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Regression — receipt sync still works**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ReceiptSync`
Expected: PASS (existing tests green).

- [ ] **Step 9: Commit**

```bash
git add app/Enums/ReceiptProvider.php database/migrations/2026_07_09_100000_generalize_payment_receipts_for_providers.php app/Models/PaymentReceipt.php app/Services/Payment/ReceiptSyncService.php tests/Feature/Fiscal/PaymentReceiptGeneralizationTest.php
git commit -m "feat(fiscal): generalize payment_receipts for multiple providers"
```

---

### Task 2: Контракт `Fiscalizer` + DTO + фейк + фабрика

**Files:**
- Create: `app/Contracts/Fiscalizer.php`, `app/DTOs/Payment/FiscalReceiptResult.php`, `app/Services/Payment/Fiscal/FakeFiscalizer.php`
- Modify: `app/Providers/AppServiceProvider.php:58-62`
- Test: `tests/Unit/Services/Payment/Fiscal/FiscalizerFactoryTest.php`

**Interfaces:**
- Produces:
  - `App\Contracts\Fiscalizer` — `fiscalizeSale(Order $order, PaymentType $paymentType, float $amount): FiscalReceiptResult`, `fiscalizeRefund(Order $order, float $amount): FiscalReceiptResult`.
  - `App\DTOs\Payment\FiscalReceiptResult` — `readonly`: `ReceiptProvider $provider`, `?string $externalId`, `string $status` (`pending|succeeded|failed`), `?string $fn=null`, `?string $fd=null`, `?string $fp=null`, `?string $receiptUrl=null`, `?\DateTimeInterface $registeredAt=null`, `array $raw=[]`.
  - Контейнер резолвит `Fiscalizer` по `IntegrationConfigService::getDriver('fiscal')`: `atol` → `AtolFiscalizer`, иначе → `YooKassaFiscalizer`.

- [ ] **Step 1: Failing test**

```php
// tests/Unit/Services/Payment/Fiscal/FiscalizerFactoryTest.php
<?php
declare(strict_types=1);
namespace Tests\Unit\Services\Payment\Fiscal;
use App\Contracts\Fiscalizer;
use App\Models\IntegrationConfig;
use App\Services\Payment\Fiscal\AtolFiscalizer;
use App\Services\Payment\Fiscal\YooKassaFiscalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FiscalizerFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_atol_when_fiscal_driver_is_atol(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol',
            'credentials' => ['atol' => []], 'is_active' => true]);
        $this->assertInstanceOf(AtolFiscalizer::class, app(Fiscalizer::class));
    }

    public function test_resolves_yookassa_by_default(): void
    {
        $this->assertInstanceOf(YooKassaFiscalizer::class, app(Fiscalizer::class));
    }
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=FiscalizerFactoryTest`
Expected: FAIL (interface/classes not found).

- [ ] **Step 3: Contract + DTO**

```php
// app/Contracts/Fiscalizer.php
<?php
declare(strict_types=1);
namespace App\Contracts;
use App\DTOs\Payment\FiscalReceiptResult;
use App\Enums\PaymentType;
use App\Models\Order;

interface Fiscalizer
{
    public function fiscalizeSale(Order $order, PaymentType $paymentType, float $amount): FiscalReceiptResult;
    public function fiscalizeRefund(Order $order, float $amount): FiscalReceiptResult;
}
```

```php
// app/DTOs/Payment/FiscalReceiptResult.php
<?php
declare(strict_types=1);
namespace App\DTOs\Payment;
use App\Enums\ReceiptProvider;

final readonly class FiscalReceiptResult
{
    public function __construct(
        public ReceiptProvider $provider,
        public ?string $externalId,
        public string $status,           // pending | succeeded | failed
        public ?string $fn = null,
        public ?string $fd = null,
        public ?string $fp = null,
        public ?string $receiptUrl = null,
        public ?\DateTimeInterface $registeredAt = null,
        public array $raw = [],
    ) {}
}
```

- [ ] **Step 4: Fake fiscalizer**

```php
// app/Services/Payment/Fiscal/FakeFiscalizer.php
<?php
declare(strict_types=1);
namespace App\Services\Payment\Fiscal;
use App\Contracts\Fiscalizer;
use App\DTOs\Payment\FiscalReceiptResult;
use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Models\Order;

final class FakeFiscalizer implements Fiscalizer
{
    /** @var array<int, array{order:int, type:string, amount:float}> */
    public array $sales = [];
    public array $refunds = [];

    public function fiscalizeSale(Order $order, PaymentType $paymentType, float $amount): FiscalReceiptResult
    {
        $this->sales[] = ['order' => $order->id, 'type' => $paymentType->value, 'amount' => $amount];
        return new FiscalReceiptResult(ReceiptProvider::Atol, 'fake-uuid', 'pending');
    }

    public function fiscalizeRefund(Order $order, float $amount): FiscalReceiptResult
    {
        $this->refunds[] = ['order' => $order->id, 'amount' => $amount];
        return new FiscalReceiptResult(ReceiptProvider::Atol, 'fake-refund-uuid', 'pending');
    }
}
```

- [ ] **Step 5: Factory binding in AppServiceProvider**

После блока `PaymentGateway` (`app/Providers/AppServiceProvider.php:62`) добавить:

```php
        // Fiscalizer — выбирается по активному драйверу интеграции 'fiscal'
        $this->app->bind(\App\Contracts\Fiscalizer::class, function ($app) {
            $driver = \App\Services\IntegrationConfigService::getDriver('fiscal');

            return $driver === 'atol'
                ? $app->make(\App\Services\Payment\Fiscal\AtolFiscalizer::class)
                : $app->make(\App\Services\Payment\Fiscal\YooKassaFiscalizer::class);
        });
```

> Примечание: `AtolFiscalizer` и `YooKassaFiscalizer` создаются в Задачах 4-5; до их создания фабрика не резолвится. Чтобы этот тест прошёл в изоляции — реализуй заглушки классов (пустые `implements Fiscalizer`, методы `throw new \LogicException`) в Шаге 6, наполнишь в Задачах 4-5.

- [ ] **Step 6: Stub classes (заполняются в Задачах 4-5)**

Создать `app/Services/Payment/Fiscal/AtolFiscalizer.php` и `YooKassaFiscalizer.php` как `final class ... implements Fiscalizer` с обоими методами `{ throw new \LogicException('not implemented'); }`.

- [ ] **Step 7: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=FiscalizerFactoryTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Contracts/Fiscalizer.php app/DTOs/Payment/FiscalReceiptResult.php app/Services/Payment/Fiscal/ app/Providers/AppServiceProvider.php tests/Unit/Services/Payment/Fiscal/FiscalizerFactoryTest.php
git commit -m "feat(fiscal): add Fiscalizer contract, DTO, fake and driver factory"
```

---

### Task 3: `AtolClient` — тонкий HTTP-клиент АТОЛ Онлайн v4

**Files:**
- Create: `app/Services/Payment/Fiscal/AtolClient.php`
- Modify: `config/services.php` (блок `atol`)
- Test: `tests/Unit/Services/Payment/Fiscal/AtolClientTest.php`

**Interfaces:**
- Consumes: `IntegrationConfigService::getCredentials('fiscal')['atol']` (`mode`, `login`, `password`, `group_code`).
- Produces:
  - `AtolClient::register(string $operation, array $receipt, string $externalId): array` — POST `/{group_code}/{operation}?token=…`, `operation ∈ {sell, sell_refund}`; тело `{external_id, receipt, timestamp, callback_url}`; возвращает `['uuid'=>..., 'status'=>'wait'|'fail', 'error'=>...]`.
  - `AtolClient::report(string $uuid): array` — GET `/{group_code}/report/{uuid}?token=…`; возвращает decoded JSON (`status`, `payload`, `error`).
  - Токен кэшируется в `Cache` ключ `atol:token:{login}:{mode}` TTL 23 ч; при 401/`error.code` авторизации — сбрасывается и повторяется 1 раз.

- [ ] **Step 1: Failing test (с `Http::fake`)**

```php
// tests/Unit/Services/Payment/Fiscal/AtolClientTest.php
<?php
declare(strict_types=1);
namespace Tests\Unit\Services\Payment\Fiscal;
use App\Models\IntegrationConfig;
use App\Services\Payment\Fiscal\AtolClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AtolClientTest extends TestCase
{
    use RefreshDatabase;

    private function config(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p', 'group_code' => 'grp']]]);
    }

    public function test_register_authorizes_then_posts_sell(): void
    {
        $this->config();
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN', 'error' => null], 200),
            '*/grp/sell*' => Http::response(['uuid' => 'U1', 'status' => 'wait', 'error' => null], 200),
        ]);

        $res = app(AtolClient::class)->register('sell', ['total' => 100], 'order-1-sale');

        $this->assertSame('U1', $res['uuid']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/grp/sell') && str_contains($r->url(), 'token=TKN'));
    }

    public function test_report_returns_done_payload(): void
    {
        $this->config();
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN'], 200),
            '*/grp/report/U1*' => Http::response(['uuid' => 'U1', 'status' => 'done',
                'payload' => ['fn_number' => 'FN', 'fiscal_document_number' => 'FD',
                    'fiscal_document_attribute' => 'FP', 'ofd_receipt_url' => 'http://x']], 200),
        ]);

        $res = app(AtolClient::class)->report('U1');
        $this->assertSame('done', $res['status']);
        $this->assertSame('FN', $res['payload']['fn_number']);
    }
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AtolClientTest`
Expected: FAIL (class not found).

- [ ] **Step 3: config/services.php — блок atol**

Добавить в `config/services.php`:

```php
    'atol' => [
        'base_url_test' => env('ATOL_BASE_URL_TEST', 'https://testonline.atol.ru/possystem/v4'),
        'base_url_live' => env('ATOL_BASE_URL_LIVE', 'https://online.atol.ru/possystem/v4'),
        'callback_url' => env('ATOL_CALLBACK_URL', env('APP_URL').'/api/v1/webhooks/atol'),
        'timeout' => (int) env('ATOL_TIMEOUT', 15),
    ],
```

- [ ] **Step 4: Implement AtolClient**

```php
// app/Services/Payment/Fiscal/AtolClient.php
<?php
declare(strict_types=1);
namespace App\Services\Payment\Fiscal;
use App\Services\IntegrationConfigService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class AtolClient
{
    public function register(string $operation, array $receipt, string $externalId): array
    {
        $creds = $this->creds();
        $body = [
            'external_id' => $externalId,
            'receipt' => $receipt,
            'timestamp' => now()->format('d.m.Y H:i:s'),
            'callback_url' => (string) config('services.atol.callback_url'),
        ];

        $do = fn (string $token) => Http::timeout($this->timeout())
            ->post($this->url($creds, "/{$creds['group_code']}/{$operation}", $token), $body);

        $resp = $do($this->token($creds));
        if ($this->isAuthError($resp)) {
            $resp = $do($this->token($creds, force: true));
        }

        return $resp->json() ?? [];
    }

    public function report(string $uuid): array
    {
        $creds = $this->creds();
        $resp = Http::timeout($this->timeout())
            ->get($this->url($creds, "/{$creds['group_code']}/report/{$uuid}", $this->token($creds)));

        if ($this->isAuthError($resp)) {
            $resp = Http::timeout($this->timeout())
                ->get($this->url($creds, "/{$creds['group_code']}/report/{$uuid}", $this->token($creds, force: true)));
        }

        return $resp->json() ?? [];
    }

    private function token(array $creds, bool $force = false): string
    {
        $key = "atol:token:{$creds['login']}:{$creds['mode']}";
        if ($force) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addHours(23), function () use ($creds) {
            $resp = Http::timeout($this->timeout())->post($this->base($creds).'/getToken', [
                'login' => $creds['login'],
                'pass' => $creds['password'],
            ]);

            return (string) ($resp->json('token') ?? '');
        });
    }

    private function isAuthError(\Illuminate\Http\Client\Response $resp): bool
    {
        return $resp->status() === 401
            || in_array($resp->json('error.code'), [5, 6, 12, 14], true); // группа auth-ошибок АТОЛ
    }

    private function base(array $creds): string
    {
        return $creds['mode'] === 'live'
            ? (string) config('services.atol.base_url_live')
            : (string) config('services.atol.base_url_test');
    }

    private function url(array $creds, string $path, string $token): string
    {
        return $this->base($creds).$path.'?token='.$token;
    }

    private function timeout(): int
    {
        return (int) config('services.atol.timeout', 15);
    }

    /** @return array{mode:string, login:string, password:string, group_code:string} */
    private function creds(): array
    {
        $c = IntegrationConfigService::getCredentials('fiscal')['atol'] ?? [];

        return [
            'mode' => $c['mode'] ?? 'test',
            'login' => $c['login'] ?? '',
            'password' => $c['password'] ?? '',
            'group_code' => $c['group_code'] ?? '',
        ];
    }
}
```

- [ ] **Step 5: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AtolClientTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payment/Fiscal/AtolClient.php config/services.php tests/Unit/Services/Payment/Fiscal/AtolClientTest.php
git commit -m "feat(fiscal): add thin AtolClient (getToken/register/report) with token cache"
```

---

### Task 4: `AtolReceiptBuilder` + `AtolFiscalizer`

**Files:**
- Create: `app/Services/Payment/Fiscal/AtolReceiptBuilder.php`
- Modify: `app/Services/Payment/Fiscal/AtolFiscalizer.php` (наполнить заглушку из Задачи 2)
- Test: `tests/Unit/Services/Payment/Fiscal/AtolReceiptBuilderTest.php`, `tests/Feature/Fiscal/AtolFiscalizerTest.php`

**Interfaces:**
- Consumes: `AtolClient::register()`, `IntegrationConfigService::getCredentials('fiscal')['atol']`, `PaymentReceipt`, `ReceiptProvider`.
- Produces:
  - `AtolReceiptBuilder::build(Order $order, PaymentType $paymentType, float $amount): array` — объект `receipt` АТОЛ (`client`, `company`, `items`, `payments`, `total`). Позиции = товары + доставка; сумма позиций строго == `$amount` (распределение как в `ReceiptBuilder::distribute`). `payments[0].type` = 0 для Cash, 1 для Card. `payment_method` = `full_payment` для Cash, `full_prepayment` для Card. `vat.type` из `vat_code` товара.
  - `AtolFiscalizer::fiscalizeSale/Refund` — строит receipt, зовёт `register('sell'|'sell_refund', …, "{order}-{op}")`, пишет `PaymentReceipt` в статусе `pending` с `provider=atol`, `external_id=uuid`, возвращает `FiscalReceiptResult`.

- [ ] **Step 1: Failing test — builder maps fields**

```php
// tests/Unit/Services/Payment/Fiscal/AtolReceiptBuilderTest.php
<?php
declare(strict_types=1);
namespace Tests\Unit\Services\Payment\Fiscal;
use App\Enums\PaymentType;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Services\Payment\Fiscal\AtolReceiptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AtolReceiptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_receipt_uses_payment_type_0_and_full_payment(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'seller_name' => 'ООО Ромашка',
                'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 100.0);

        $this->assertSame(0, $receipt['payments'][0]['type']);
        $this->assertSame(100.0, $receipt['payments'][0]['sum']);
        $this->assertSame('full_payment', $receipt['items'][0]['payment_method']);
        $this->assertSame('7700000000', $receipt['company']['inn']);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($receipt['items'], 'sum')), 0.001);
    }

    public function test_card_receipt_uses_payment_type_1_and_full_prepayment(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
        $order = Order::factory()->hasItems(1)->create(['total' => 50]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Card, 50.0);

        $this->assertSame(1, $receipt['payments'][0]['type']);
        $this->assertSame('full_prepayment', $receipt['items'][0]['payment_method']);
    }
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AtolReceiptBuilderTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement AtolReceiptBuilder**

```php
// app/Services/Payment/Fiscal/AtolReceiptBuilder.php
<?php
declare(strict_types=1);
namespace App\Services\Payment\Fiscal;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Services\IntegrationConfigService;

final class AtolReceiptBuilder
{
    /** @return array<string, mixed> */
    public function build(Order $order, PaymentType $paymentType, float $amount): array
    {
        $order->loadMissing(['items', 'user']);
        $creds = IntegrationConfigService::getCredentials('fiscal')['atol'] ?? [];

        $isCash = $paymentType === PaymentType::Cash;
        $paymentMethod = $isCash ? 'full_payment' : 'full_prepayment';

        $items = $this->distribute($this->lines($order, $paymentMethod, $creds), $amount);

        return [
            'client' => array_filter([
                'email' => $order->user?->email,
                'phone' => $this->phone($order->user?->phone),
            ]),
            'company' => array_filter([
                'email' => $creds['company_email'] ?? null,
                'sno' => $creds['sno'] ?? null,
                'inn' => $creds['inn'] ?? null,
                'payment_address' => $creds['payment_address'] ?? null,
            ]),
            'items' => $items,
            'payments' => [[
                'type' => $isCash ? 0 : 1,
                'sum' => round($amount, 2),
            ]],
            'total' => round($amount, 2),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function lines(Order $order, string $paymentMethod, array $creds): array
    {
        $defaultVat = $creds['vat'] ?? 'vat20';
        $lines = [];
        foreach ($order->items as $item) {
            $lines[] = [
                'name' => mb_substr((string) $item->name, 0, 128),
                'price' => (float) $item->price,
                'quantity' => (float) $item->quantity,
                'sum' => round((float) $item->total, 2),
                'payment_method' => $paymentMethod,
                'payment_object' => $creds['payment_object'] ?? 'commodity',
                'vat' => ['type' => $this->vatType($item->vat_code ?? null, $defaultVat)],
            ];
        }

        if (($order->delivery_price ?? 0) > 0) {
            $lines[] = [
                'name' => 'Доставка',
                'price' => (float) $order->delivery_price,
                'quantity' => 1.0,
                'sum' => round((float) $order->delivery_price, 2),
                'payment_method' => $paymentMethod,
                'payment_object' => 'service',
                'vat' => ['type' => $defaultVat],
            ];
        }

        return $lines;
    }

    private function vatType(?int $vatCode, string $default): string
    {
        return match ($vatCode) {
            1 => 'none', 2 => 'vat0', 3 => 'vat10', 4 => 'vat20', 5 => 'vat110', 6 => 'vat120',
            default => $default,
        };
    }

    /**
     * Пропорционально подогнать сумму позиций под целевую сумму (остаток — на последнюю).
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function distribute(array $lines, float $target): array
    {
        $current = array_sum(array_map(static fn ($l) => $l['sum'], $lines));
        if ($current <= 0 || $lines === []) {
            return $lines;
        }
        $ratio = $target / $current;
        $acc = 0.0;
        $last = count($lines) - 1;
        foreach ($lines as $i => &$line) {
            if ($i === $last) {
                $line['sum'] = round($target - $acc, 2);
            } else {
                $line['sum'] = round($line['sum'] * $ratio, 2);
                $acc += $line['sum'];
            }
            $qty = $line['quantity'] > 0 ? $line['quantity'] : 1;
            $line['price'] = round($line['sum'] / $qty, 2);
        }

        return $lines;
    }

    private function phone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);

        return $digits === '' ? null : '+'.$digits;
    }
}
```

> Примечание для реализатора: подтвердить имена колонок `Order` — `delivery_price`, `OrderItem` — `name`, `price`, `quantity`, `total`, `vat_code`. Если названия иные (проверить `app/Models/OrderItem.php`), подставить фактические. Тест на распределение суммы поймает арифметику; имена полей — на этапе Feature-теста Задачи ниже.

- [ ] **Step 4: Feature test — AtolFiscalizer persists pending receipt**

```php
// tests/Feature/Fiscal/AtolFiscalizerTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Fiscal;
use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Services\Payment\Fiscal\AtolFiscalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AtolFiscalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscalize_sale_registers_and_stores_pending_receipt(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN']),
            '*/grp/sell*' => Http::response(['uuid' => 'U1', 'status' => 'wait']),
        ]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $result = app(AtolFiscalizer::class)->fiscalizeSale($order, PaymentType::Cash, 100.0);

        $this->assertSame('pending', $result->status);
        $this->assertSame('U1', $result->externalId);
        $this->assertDatabaseHas('payment_receipts', [
            'order_id' => $order->id, 'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U1', 'status' => 'pending', 'type' => 'payment',
        ]);
    }
}
```

- [ ] **Step 5: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AtolFiscalizerTest`
Expected: FAIL (`AtolFiscalizer` throws LogicException).

- [ ] **Step 6: Implement AtolFiscalizer**

```php
// app/Services/Payment/Fiscal/AtolFiscalizer.php
<?php
declare(strict_types=1);
namespace App\Services\Payment\Fiscal;
use App\Contracts\Fiscalizer;
use App\DTOs\Payment\FiscalReceiptResult;
use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Models\Order;
use App\Models\PaymentReceipt;

final class AtolFiscalizer implements Fiscalizer
{
    public function __construct(
        private AtolClient $client,
        private AtolReceiptBuilder $builder,
    ) {}

    public function fiscalizeSale(Order $order, PaymentType $paymentType, float $amount): FiscalReceiptResult
    {
        return $this->register($order, $paymentType, $amount, 'sell', 'payment');
    }

    public function fiscalizeRefund(Order $order, float $amount): FiscalReceiptResult
    {
        $type = $order->payment_type ?? PaymentType::Card;

        return $this->register($order, $type, $amount, 'sell_refund', 'refund');
    }

    private function register(Order $order, PaymentType $paymentType, float $amount, string $operation, string $receiptType): FiscalReceiptResult
    {
        $externalId = "{$order->id}-{$operation}";
        $receipt = $this->builder->build($order, $paymentType, $amount);
        $response = $this->client->register($operation, $receipt, $externalId);

        $uuid = $response['uuid'] ?? null;
        $failed = ($response['status'] ?? null) === 'fail' || $uuid === null;

        $model = PaymentReceipt::updateOrCreate(
            ['provider' => ReceiptProvider::Atol->value, 'external_id' => $uuid ?? $externalId],
            [
                'order_id' => $order->id,
                'type' => $receiptType,
                'status' => $failed ? 'canceled' : 'pending', // enum PaymentTransactionStatus не имеет Failed → canceled
                'amount' => round($amount, 2),
                'items' => $receipt['items'],
                'raw' => $response,
            ],
        );

        if ($failed) {
            throw new \RuntimeException('ATOL register failed: '.json_encode($response['error'] ?? $response, JSON_UNESCAPED_UNICODE));
        }

        return new FiscalReceiptResult(ReceiptProvider::Atol, $uuid, 'pending', raw: $response);
    }
}
```

- [ ] **Step 7: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AtolReceiptBuilderTest && /opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AtolFiscalizerTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Payment/Fiscal/AtolReceiptBuilder.php app/Services/Payment/Fiscal/AtolFiscalizer.php tests/Unit/Services/Payment/Fiscal/AtolReceiptBuilderTest.php tests/Feature/Fiscal/AtolFiscalizerTest.php
git commit -m "feat(fiscal): AtolReceiptBuilder + AtolFiscalizer (sell/sell_refund → pending receipt)"
```

---

### Task 5: `YooKassaFiscalizer`

**Files:**
- Modify: `app/Services/Payment/Fiscal/YooKassaFiscalizer.php` (наполнить заглушку)
- Test: `tests/Feature/Fiscal/YooKassaFiscalizerTest.php`

**Interfaces:**
- Consumes: `PaymentGateway` (для онлайна ничего доп. не делает — чек уже ушёл в платеже; метод возвращает `succeeded`-noop c провайдером yookassa), `YooKassaService`-совместимый вызов `POST /receipts` для наличных (независимый чек).
- Produces: `YooKassaFiscalizer::fiscalizeSale` — для `Card` возвращает noop (`FiscalReceiptResult(Yookassa, null, 'succeeded')`, чек регистрирует ЮKassa в платеже → `ReceiptSyncService`); для `Cash` шлёт `POST /receipts` (independent receipt) через `PaymentGateway`-слой и пишет `payment_receipts pending`; `fiscalizeRefund` — noop (чек возврата уже в рефанде ЮKassa).

> **Уточнение на реализацию:** метод `POST /receipts` ЮKassa не входит в текущий `PaymentGateway`-контракт. Реализатор добавляет в `YooKassaService` публичный `createIndependentReceipt(array $receipt): array` (POST `/receipts`, тело: `{type:'payment', send:true, customer, settlements:[{type:'cash', amount}], items}`) и в `PaymentGateway` — сигнатуру. `YooKassaFiscalizer` для `Cash` собирает receipt через существующий `ReceiptBuilder::forOrder($order, $amount)` (переиспользуем маппинг ЮKassa) + `settlements` наличными.

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Fiscal/YooKassaFiscalizerTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Fiscal;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Services\Payment\Fiscal\YooKassaFiscalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class YooKassaFiscalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_sale_is_noop_handled_by_acquiring(): void
    {
        $order = Order::factory()->create(['total' => 100]);
        $result = app(YooKassaFiscalizer::class)->fiscalizeSale($order, PaymentType::Card, 100.0);
        $this->assertSame('succeeded', $result->status);
    }

    public function test_cash_sale_creates_independent_receipt(): void
    {
        // FakePaymentGateway (test env) фиксирует вызов createIndependentReceipt
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);
        $result = app(YooKassaFiscalizer::class)->fiscalizeSale($order, PaymentType::Cash, 100.0);
        $this->assertSame('pending', $result->status);
        $this->assertDatabaseHas('payment_receipts', ['order_id' => $order->id, 'provider' => 'yookassa', 'status' => 'pending']);
    }
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=YooKassaFiscalizerTest`
Expected: FAIL.

- [ ] **Step 3: Extend PaymentGateway + YooKassaService + FakePaymentGateway**

- В `app/Contracts/PaymentGateway.php` добавить `public function createIndependentReceipt(array $payload): array;`
- В `app/Services/Payment/YooKassaService.php` реализовать через `request('POST', '/receipts', $payload)`.
- В fake-гейтвее тестов (`FakePaymentGateway`) добавить метод, возвращающий `['id' => 'yk-ind-1', 'status' => 'pending']` и фиксирующий вызов.

- [ ] **Step 4: Implement YooKassaFiscalizer**

```php
// app/Services/Payment/Fiscal/YooKassaFiscalizer.php
<?php
declare(strict_types=1);
namespace App\Services\Payment\Fiscal;
use App\Contracts\Fiscalizer;
use App\Contracts\PaymentGateway;
use App\DTOs\Payment\FiscalReceiptResult;
use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Services\Payment\ReceiptBuilder;

final class YooKassaFiscalizer implements Fiscalizer
{
    public function __construct(
        private PaymentGateway $gateway,
        private ReceiptBuilder $receiptBuilder,
    ) {}

    public function fiscalizeSale(Order $order, PaymentType $paymentType, float $amount): FiscalReceiptResult
    {
        // Онлайн-оплата: чек уходит внутри платежа ЮKassa → ReceiptSyncService.
        if ($paymentType === PaymentType::Card) {
            return new FiscalReceiptResult(ReceiptProvider::Yookassa, null, 'succeeded');
        }

        // Наличные: независимый чек без движения денег (POST /receipts).
        $receipt = $this->receiptBuilder->forOrder($order, $amount);
        $payload = [
            'type' => 'payment',
            'send' => true,
            'customer' => array_filter([
                'email' => $order->user?->email,
                'phone' => $order->user?->phone,
            ]),
            'settlements' => [[
                'type' => 'cash',
                'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'],
            ]],
            'items' => $receipt?->toArray()['items'] ?? [],
        ];

        $response = $this->gateway->createIndependentReceipt($payload);
        $externalId = $response['id'] ?? null;

        if ($externalId !== null) {
            PaymentReceipt::updateOrCreate(
                ['provider' => ReceiptProvider::Yookassa->value, 'external_id' => $externalId],
                [
                    'order_id' => $order->id,
                    'yookassa_receipt_id' => $externalId,
                    'type' => 'payment',
                    'status' => $response['status'] ?? 'pending',
                    'amount' => round($amount, 2),
                    'items' => $payload['items'],
                    'raw' => $response,
                ],
            );
        }

        return new FiscalReceiptResult(ReceiptProvider::Yookassa, $externalId, 'pending', raw: $response);
    }

    public function fiscalizeRefund(Order $order, float $amount): FiscalReceiptResult
    {
        // Возврат по онлайну фискализируется внутри рефанда ЮKassa.
        return new FiscalReceiptResult(ReceiptProvider::Yookassa, null, 'succeeded');
    }
}
```

- [ ] **Step 5: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=YooKassaFiscalizerTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Contracts/PaymentGateway.php app/Services/Payment/YooKassaService.php app/Services/Payment/Fiscal/YooKassaFiscalizer.php tests/Feature/Fiscal/YooKassaFiscalizerTest.php
git commit -m "feat(fiscal): YooKassaFiscalizer (card noop, cash independent receipt)"
```

---

### Task 6: Очередь — `FiscalizeSaleJob`, `FiscalizeRefundJob`, `PollAtolReceiptJob`

**Files:**
- Create: `app/Jobs/FiscalizeSaleJob.php`, `app/Jobs/FiscalizeRefundJob.php`, `app/Jobs/PollAtolReceiptJob.php`
- Test: `tests/Feature/Fiscal/FiscalizeJobsTest.php`

**Interfaces:**
- Consumes: `Fiscalizer` (из контейнера), `AtolClient`, `PaymentReceipt`, `FiscalReceiptResult`.
- Produces:
  - `FiscalizeSaleJob(int $orderId, string $paymentType, float $amount)` — `handle(Fiscalizer)` вызывает `fiscalizeSale`; при АТОЛ-`pending` диспатчит `PollAtolReceiptJob($receiptId, attempt:1)` с задержкой; ошибки → `throw` (ретрай очереди) + `report` в Sentry на `failed()`.
  - `FiscalizeRefundJob(int $orderId, float $amount)` — аналогично `fiscalizeRefund`.
  - `PollAtolReceiptJob(int $paymentReceiptId, int $attempt)` — `report(uuid)`; `done` → пишет ФН/ФД/ФП/`receipt_url`/`registered_at`, `status=succeeded`, диспатч `SendReceiptReadyNotification`; `fail` → `status=canceled` + Sentry; `wait` и `attempt<6` → повторный self-dispatch c backoff.
  - `$tries=5`, backoff-массив `[10,30,60,180,300]`.

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Fiscal/FiscalizeJobsTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Fiscal;
use App\Enums\PaymentType;
use App\Jobs\FiscalizeSaleJob;
use App\Jobs\PollAtolReceiptJob;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class FiscalizeJobsTest extends TestCase
{
    use RefreshDatabase;

    private function atol(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
    }

    public function test_sale_job_registers_and_queues_poll(): void
    {
        $this->atol();
        Queue::fake();
        Http::fake(['*/getToken' => Http::response(['token' => 'T']),
            '*/grp/sell*' => Http::response(['uuid' => 'U1', 'status' => 'wait'])]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        (new FiscalizeSaleJob($order->id, PaymentType::Cash->value, 100.0))->handle(app(\App\Contracts\Fiscalizer::class));

        $this->assertDatabaseHas('payment_receipts', ['external_id' => 'U1', 'status' => 'pending']);
        Queue::assertPushed(PollAtolReceiptJob::class);
    }

    public function test_poll_job_writes_fiscal_data_on_done(): void
    {
        $this->atol();
        $order = Order::factory()->create();
        $receipt = PaymentReceipt::create(['order_id' => $order->id, 'provider' => 'atol',
            'external_id' => 'U1', 'type' => 'payment', 'status' => 'pending', 'amount' => 100, 'items' => [], 'raw' => []]);
        Http::fake(['*/getToken' => Http::response(['token' => 'T']),
            '*/grp/report/U1*' => Http::response(['status' => 'done', 'payload' => [
                'fn_number' => 'FN1', 'fiscal_document_number' => 'FD1', 'fiscal_document_attribute' => 'FP1',
                'ofd_receipt_url' => 'http://ofd/x', 'receipt_datetime' => '2026-07-09T12:00:00']])]);

        (new PollAtolReceiptJob($receipt->id, 1))->handle(app(\App\Services\Payment\Fiscal\AtolClient::class));

        $this->assertDatabaseHas('payment_receipts', ['id' => $receipt->id, 'status' => 'succeeded',
            'fiscal_storage_number' => 'FN1', 'fiscal_document_number' => 'FD1', 'fiscal_attribute' => 'FP1',
            'receipt_url' => 'http://ofd/x']);
    }
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=FiscalizeJobsTest`
Expected: FAIL (jobs not found).

- [ ] **Step 3: Implement FiscalizeSaleJob**

```php
// app/Jobs/FiscalizeSaleJob.php
<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Contracts\Fiscalizer;
use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Models\Order;
use App\Models\PaymentReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class FiscalizeSaleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [10, 30, 60, 180, 300];
    }

    public function __construct(
        private int $orderId,
        private string $paymentType,
        private float $amount,
    ) {}

    public function handle(Fiscalizer $fiscalizer): void
    {
        $order = Order::with(['items', 'user'])->find($this->orderId);
        if ($order === null) {
            return;
        }

        $result = $fiscalizer->fiscalizeSale($order, PaymentType::from($this->paymentType), $this->amount);

        // АТОЛ: pending-чек надо доопросить статусом.
        if ($result->provider === ReceiptProvider::Atol && $result->status === 'pending' && $result->externalId !== null) {
            $receipt = PaymentReceipt::where('provider', ReceiptProvider::Atol->value)
                ->where('external_id', $result->externalId)->first();
            if ($receipt !== null) {
                PollAtolReceiptJob::dispatch($receipt->id, 1)->delay(now()->addSeconds(10));
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FiscalizeSaleJob failed', ['order_id' => $this->orderId, 'error' => $e->getMessage()]);
        if (app()->bound('sentry')) {
            app('sentry')->captureException($e);
        }
    }
}
```

- [ ] **Step 4: Implement FiscalizeRefundJob** (по образцу Sale, вызывает `fiscalizeRefund($order, $amount)`, тот же `failed()`; АТОЛ-pending → `PollAtolReceiptJob`).

- [ ] **Step 5: Implement PollAtolReceiptJob**

```php
// app/Jobs/PollAtolReceiptJob.php
<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentReceipt;
use App\Services\Payment\Fiscal\AtolClient;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class PollAtolReceiptJob implements ShouldQueue
{
    use Queueable;

    private const MAX_ATTEMPTS = 6;
    private const DELAYS = [15, 30, 60, 120, 300, 600];

    public function __construct(
        private int $paymentReceiptId,
        private int $attempt,
    ) {}

    public function handle(AtolClient $client): void
    {
        $receipt = PaymentReceipt::find($this->paymentReceiptId);
        if ($receipt === null || $receipt->status === PaymentTransactionStatus::Succeeded->value) {
            return;
        }

        $report = $client->report((string) $receipt->external_id);
        $status = $report['status'] ?? 'wait';

        if ($status === 'done') {
            $p = $report['payload'] ?? [];
            $receipt->update([
                'status' => 'succeeded',
                'fiscal_storage_number' => $p['fn_number'] ?? null,
                'fiscal_document_number' => $p['fiscal_document_number'] ?? null,
                'fiscal_attribute' => $p['fiscal_document_attribute'] ?? null,
                'receipt_url' => $p['ofd_receipt_url'] ?? null,
                'registered_at' => isset($p['receipt_datetime']) ? Carbon::parse($p['receipt_datetime']) : now(),
                'raw' => $report,
            ]);
            SendReceiptReadyNotification::dispatch($receipt->id);

            return;
        }

        if ($status === 'fail') {
            $receipt->update(['status' => 'canceled', 'raw' => $report]);
            Log::error('ATOL receipt failed', ['receipt' => $receipt->id, 'report' => $report]);
            if (app()->bound('sentry')) {
                app('sentry')->captureMessage('ATOL fiscalization failed for receipt '.$receipt->id);
            }

            return;
        }

        // wait — повторить
        if ($this->attempt < self::MAX_ATTEMPTS) {
            self::dispatch($this->paymentReceiptId, $this->attempt + 1)
                ->delay(now()->addSeconds(self::DELAYS[$this->attempt] ?? 600));
        }
    }
}
```

- [ ] **Step 6: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=FiscalizeJobsTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/FiscalizeSaleJob.php app/Jobs/FiscalizeRefundJob.php app/Jobs/PollAtolReceiptJob.php tests/Feature/Fiscal/FiscalizeJobsTest.php
git commit -m "feat(fiscal): fiscalization queue jobs (sale/refund/poll) with retries + Sentry"
```

---

### Task 7: `ReceiptBuilder` — не слать ЮKassa-чек при драйвере АТОЛ

**Files:**
- Modify: `app/Services/Payment/ReceiptBuilder.php:308-313`
- Test: `tests/Unit/Services/Payment/ReceiptBuilderFiscalDriverTest.php`

**Interfaces:**
- Consumes: `IntegrationConfigService::getDriver('fiscal')`.
- Produces: `sendReceiptEnabled()` возвращает `false` при `getDriver('fiscal') === 'atol'` (тогда `forOrder()`/др. отдают `null` → ЮKassa не получает `receipt`). При `yookassa`/`null` — прежнее поведение.

- [ ] **Step 1: Failing test**

```php
// tests/Unit/Services/Payment/ReceiptBuilderFiscalDriverTest.php
<?php
declare(strict_types=1);
namespace Tests\Unit\Services\Payment;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Services\Payment\ReceiptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReceiptBuilderFiscalDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_receipt_when_fiscal_driver_is_atol(): void
    {
        IntegrationConfig::create(['integration_type' => 'payment', 'driver' => 'yookassa', 'is_active' => true,
            'credentials' => ['yookassa' => ['send_receipt' => true]]]);
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => []]]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $this->assertNull(app(ReceiptBuilder::class)->forOrder($order));
    }

    public function test_builds_receipt_when_fiscal_driver_is_yookassa(): void
    {
        IntegrationConfig::create(['integration_type' => 'payment', 'driver' => 'yookassa', 'is_active' => true,
            'credentials' => ['yookassa' => ['send_receipt' => true]]]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $this->assertNotNull(app(ReceiptBuilder::class)->forOrder($order));
    }
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ReceiptBuilderFiscalDriverTest`
Expected: FAIL (first test — receipt is built, not null).

- [ ] **Step 3: Modify sendReceiptEnabled**

`app/Services/Payment/ReceiptBuilder.php:308-313`:

```php
    private function sendReceiptEnabled(): bool
    {
        // Если фискализирует АТОЛ — ЮKassa чек НЕ шлём (иначе двойной чек).
        if (\App\Services\IntegrationConfigService::getDriver('fiscal') === 'atol') {
            return false;
        }

        $value = $this->credentials()['send_receipt'] ?? config('services.yookassa.send_receipt', true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
```

- [ ] **Step 4: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ReceiptBuilderFiscalDriverTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payment/ReceiptBuilder.php tests/Unit/Services/Payment/ReceiptBuilderFiscalDriverTest.php
git commit -m "feat(fiscal): skip YooKassa receipt when ATOL is the active fiscalizer"
```

---

### Task 8: Триггер онлайн-фискализации после `payment.succeeded`

**Files:**
- Modify: `app/Services/Payment/PaymentService.php` (`capturePayment` success-ветка ~172-179; `handlePaymentSucceeded` ~503-561)
- Test: `tests/Feature/Fiscal/OnlineFiscalizationTriggerTest.php`

**Interfaces:**
- Consumes: `FiscalizeSaleJob`, `IntegrationConfigService::getDriver('fiscal')`.
- Produces: после успешного capture (обе точки, где ставится `is_paid=true` по online-оплате) при `getDriver('fiscal')==='atol'` — `FiscalizeSaleJob::dispatch($order->id, 'card', $capturedAmount)`. При `yookassa` — ничего (чек в платеже). Идемпотентно: не диспатчим повторно, если по заказу уже есть `payment`-чек.

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Fiscal/OnlineFiscalizationTriggerTest.php — ключевой кейс
public function test_atol_sale_job_dispatched_after_card_payment_succeeded(): void
{
    IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true, 'credentials' => ['atol' => []]]);
    Queue::fake();
    // ... подготовить order с held-транзакцией, замокать gateway capture success ...
    app(PaymentService::class)->handlePaymentSucceeded($yookassaPaymentId);
    Queue::assertPushed(FiscalizeSaleJob::class, fn ($j) => $j->displayName() !== null);
}
```

> Реализатор: собрать фикстуру по образцу существующих тестов `PreauthorizedCardOrderTest` / вебхуков; проверить и обратный кейс — при `driver=yookassa` job НЕ пушится (`Queue::assertNotPushed`).

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=OnlineFiscalizationTriggerTest`
Expected: FAIL.

- [ ] **Step 3: Add dispatch helper + calls**

В `PaymentService` добавить приватный метод и вызвать его в обеих success-ветках capture (`capturePayment` после строки 179; `handlePaymentSucceeded` после установки `is_paid=true`):

```php
    private function dispatchAtolSaleIfNeeded(Order $order): void
    {
        if (\App\Services\IntegrationConfigService::getDriver('fiscal') !== 'atol') {
            return;
        }
        // идемпотентность: не бить второй чек прихода
        if ($order->receipts()->where('type', 'payment')->exists()) {
            return;
        }
        \App\Jobs\FiscalizeSaleJob::dispatch(
            $order->id,
            \App\Enums\PaymentType::Card->value,
            (float) ($order->captured_amount ?? $order->total),
        );
    }
```

Вызвать `$this->dispatchAtolSaleIfNeeded($order);` в `capturePayment` (внутри `if ($result->success)`) и в `handlePaymentSucceeded` (в обеих ветках после `is_paid=true`).

- [ ] **Step 4: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=OnlineFiscalizationTriggerTest`
Expected: PASS.

- [ ] **Step 5: Regression**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=Payment`
Expected: PASS (существующие платёжные тесты зелёные).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payment/PaymentService.php tests/Feature/Fiscal/OnlineFiscalizationTriggerTest.php
git commit -m "feat(fiscal): dispatch ATOL sale receipt after successful card capture"
```

---

### Task 9: Триггер фискализации наличных по событию `delivered`

**Files:**
- Modify: `app/RabbitMQ/Transformers/OrderEventTransformer.php` (`afterUpdate`, рядом с delivered-веткой ~576-585)
- Test: `tests/Feature/Fiscal/CashFiscalizationOnDeliveredTest.php`

**Interfaces:**
- Consumes: `FiscalizeSaleJob`, `PaymentType::Cash`, `$order`, `$eventData['grand_total']`.
- Produces: в `afterUpdate`, когда `newStatus === Delivered` && `order->payment_type === PaymentType::Cash` && `!order->is_paid`: ставит `is_paid=true` (без outbound-события) и диспатчит `FiscalizeSaleJob($order->id, 'cash', $deliveredAmount)`. `$deliveredAmount = (float)($eventData['grand_total'] ?? $order->total)`. Если сумма `<= 0` — не бить чек (полный возврат). Идемпотентно (гейт по `is_paid`).

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Fiscal/CashFiscalizationOnDeliveredTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Fiscal;
use App\Enums\PaymentType;
use App\Jobs\FiscalizeSaleJob;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CashFiscalizationOnDeliveredTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_delivered_marks_paid_and_dispatches_sale_job(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true, 'credentials' => ['atol' => []]]);
        Queue::fake();
        $order = Order::factory()->create(['payment_type' => PaymentType::Cash, 'is_paid' => false, 'total' => 300]);

        // прогнать входящее событие delivered через трансформер (по образцу существующих RabbitMQ-тестов)
        $this->consumeOrderUpdated($order, ['status' => 'delivered', 'grand_total' => 300]);

        $this->assertTrue($order->fresh()->is_paid);
        Queue::assertPushed(FiscalizeSaleJob::class, fn ($j) => true);
    }

    public function test_cash_delivered_full_return_does_not_fiscalize(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true, 'credentials' => ['atol' => []]]);
        Queue::fake();
        $order = Order::factory()->create(['payment_type' => PaymentType::Cash, 'is_paid' => false, 'total' => 300]);

        $this->consumeOrderUpdated($order, ['status' => 'delivered', 'grand_total' => 0]);

        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    public function test_delivered_twice_is_idempotent(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true, 'credentials' => ['atol' => []]]);
        Queue::fake();
        $order = Order::factory()->create(['payment_type' => PaymentType::Cash, 'is_paid' => false, 'total' => 300]);

        $this->consumeOrderUpdated($order, ['status' => 'delivered', 'grand_total' => 300]);
        $this->consumeOrderUpdated($order->fresh(), ['status' => 'delivered', 'grand_total' => 300]);

        Queue::assertPushed(FiscalizeSaleJob::class, 1); // ровно один раз
    }

    // helper consumeOrderUpdated(): собрать event payload и прогнать через
    // OrderEventTransformer (fromEvent → update → afterUpdate) как в существующих
    // тестах app/RabbitMQ; реализатор берёт паттерн из tests/Feature/RabbitMQ.
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=CashFiscalizationOnDeliveredTest`
Expected: FAIL.

- [ ] **Step 3: Add cash-branch in afterUpdate**

Внутри `if ($newStatus === OrderStatus::Delivered) { ... }` (`OrderEventTransformer` ~576-585) добавить после существующего блока:

```php
            // Фискализация наличных: факт доставки cash-заказа = факт оплаты.
            if ($order->payment_type === \App\Enums\PaymentType::Cash && ! $order->is_paid) {
                $deliveredAmount = (float) ($eventData['grand_total'] ?? $order->total);
                if ($deliveredAmount > 0) {
                    $order->forceFill(['is_paid' => true])->saveQuietly(); // без outbound-события
                    \App\Jobs\FiscalizeSaleJob::dispatch(
                        $order->id,
                        \App\Enums\PaymentType::Cash->value,
                        $deliveredAmount,
                    );
                }
            }
```

> Примечание: `saveQuietly()` — чтобы не породить исходящее `order.updated`. Проверить, что имя переменной события в `afterUpdate` — `$eventData` (по отчёту исследования — да, `$eventData['status']`).

- [ ] **Step 4: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=CashFiscalizationOnDeliveredTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/RabbitMQ/Transformers/OrderEventTransformer.php tests/Feature/Fiscal/CashFiscalizationOnDeliveredTest.php
git commit -m "feat(fiscal): fiscalize cash orders on delivered event (idempotent)"
```

---

### Task 10: Callback-вебхук АТОЛ

**Files:**
- Modify: `app/Http/Controllers/Api/V1/WebhookController.php`, `routes/api.php`
- Test: `tests/Feature/Fiscal/AtolWebhookTest.php`

**Interfaces:**
- Consumes: `PaymentReceipt`, `SendReceiptReadyNotification`.
- Produces: `POST /api/v1/webhooks/atol` — принимает `{uuid, status, payload}`; находит `payment_receipts` по `(provider=atol, external_id=uuid)`; при `done` пишет ФН/ФД/ФП/`receipt_url`/`registered_at`, `status=succeeded`, диспатч `SendReceiptReadyNotification`; при `fail` — `status=canceled`. Идемпотентно (если уже `succeeded` — 200 без действий). Возвращает 200.

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Fiscal/AtolWebhookTest.php — ключевой кейс
public function test_atol_callback_done_writes_fiscal_and_notifies(): void
{
    Queue::fake();
    $order = Order::factory()->create();
    $r = PaymentReceipt::create(['order_id'=>$order->id,'provider'=>'atol','external_id'=>'U1',
        'type'=>'payment','status'=>'pending','amount'=>100,'items'=>[],'raw'=>[]]);

    $this->postJson('/api/v1/webhooks/atol', ['uuid'=>'U1','status'=>'done','payload'=>[
        'fn_number'=>'FN','fiscal_document_number'=>'FD','fiscal_document_attribute'=>'FP',
        'ofd_receipt_url'=>'http://x','receipt_datetime'=>'2026-07-09T10:00:00']])
        ->assertOk();

    $this->assertDatabaseHas('payment_receipts', ['id'=>$r->id,'status'=>'succeeded','fiscal_storage_number'=>'FN']);
    Queue::assertPushed(SendReceiptReadyNotification::class);
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AtolWebhookTest`
Expected: FAIL (route not found → 404).

- [ ] **Step 3: Route**

В `routes/api.php` рядом с yookassa-вебхуком (после строки 54):

```php
Route::post('v1/webhooks/atol', [\App\Http\Controllers\Api\V1\WebhookController::class, 'atol']);
```

- [ ] **Step 4: Controller method `atol()`**

Добавить в `WebhookController` (вынести общую запись фискальных данных в приватный `applyAtolReport(PaymentReceipt $receipt, array $report): void` — переиспользуется с `PollAtolReceiptJob`; допустимо вызвать статику/сервис):

```php
    public function atol(Request $request): JsonResponse
    {
        $uuid = $request->input('uuid');
        $status = $request->input('status');
        $payload = $request->input('payload', []);

        Log::info('ATOL callback', ['uuid' => $uuid, 'status' => $status]);

        $receipt = \App\Models\PaymentReceipt::where('provider', \App\Enums\ReceiptProvider::Atol->value)
            ->where('external_id', $uuid)->first();

        if ($receipt === null || $receipt->status === 'succeeded') {
            return response()->json(['status' => 'ok']);
        }

        if ($status === 'done') {
            $receipt->update([
                'status' => 'succeeded',
                'fiscal_storage_number' => $payload['fn_number'] ?? null,
                'fiscal_document_number' => $payload['fiscal_document_number'] ?? null,
                'fiscal_attribute' => $payload['fiscal_document_attribute'] ?? null,
                'receipt_url' => $payload['ofd_receipt_url'] ?? null,
                'registered_at' => isset($payload['receipt_datetime'])
                    ? \Carbon\Carbon::parse($payload['receipt_datetime']) : now(),
                'raw' => $request->all(),
            ]);
            \App\Jobs\SendReceiptReadyNotification::dispatch($receipt->id);
        } elseif ($status === 'fail') {
            $receipt->update(['status' => 'canceled', 'raw' => $request->all()]);
        }

        return response()->json(['status' => 'ok']);
    }
```

- [ ] **Step 5: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AtolWebhookTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/WebhookController.php routes/api.php tests/Feature/Fiscal/AtolWebhookTest.php
git commit -m "feat(fiscal): ATOL callback webhook writes fiscal data and notifies"
```

---

### Task 11: Пуш «Чек готов»

**Files:**
- Create: `app/Jobs/SendReceiptReadyNotification.php`
- Modify: `app/Models/PaymentReceipt.php` (флаг `notified_at` — миграция-guard) — см. Step 3
- Test: `tests/Feature/Fiscal/ReceiptReadyPushTest.php`

**Interfaces:**
- Consumes: `FirebaseNotificationService::sendToUser`, `PaymentReceipt`, `NotificationType::Order`.
- Produces: `SendReceiptReadyNotification(int $paymentReceiptId)` — грузит receipt+order+user; шлёт пуш `{title:'Чек готов', body:'Фискальный чек по заказу №{id} готов'}`, data `{type:'receipt_ready', order_id, action:'open_order_details'}`; ставит `notified_at` (защита от повторной отправки при callback+poll).

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Fiscal/ReceiptReadyPushTest.php — ключевой кейс
public function test_push_sent_once_even_if_dispatched_twice(): void
{
    $fake = \Mockery::mock(FirebaseNotificationService::class);
    $fake->shouldReceive('sendToUser')->once()->andReturn(['success'=>1,'failure'=>0,'total'=>1]);
    $this->app->instance(FirebaseNotificationService::class, $fake);
    // receipt succeeded с user, push_enabled=true
    (new SendReceiptReadyNotification($receipt->id))->handle($fake);
    (new SendReceiptReadyNotification($receipt->id))->handle($fake); // второй раз — noop
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ReceiptReadyPushTest`
Expected: FAIL.

- [ ] **Step 3: Migration — notified_at на payment_receipts** (guarded, добавить в существующую или новую миграцию `2026_07_09_110000_add_notified_at_to_payment_receipts.php`), поле в `$fillable`+cast datetime.

- [ ] **Step 4: Implement job** (паттерн `SendOrderStatusNotification`: `Queueable`, `handle(FirebaseNotificationService)`, гейт `user->canReceivePush(NotificationType::Order)`, `sendToUser`, затем `receipt->update(['notified_at'=>now()])`; ранний `return`, если `notified_at !== null`).

- [ ] **Step 5: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ReceiptReadyPushTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/SendReceiptReadyNotification.php app/Models/PaymentReceipt.php database/migrations/2026_07_09_110000_add_notified_at_to_payment_receipts.php tests/Feature/Fiscal/ReceiptReadyPushTest.php
git commit -m "feat(fiscal): send 'receipt ready' push once when fiscal receipt succeeds"
```

---

### Task 12: API чеков — `receiptUrl` + `seller` из настроек фискализатора

**Files:**
- Modify: `app/Http/Resources/Api/V1/OrderReceiptResource.php:31-41`, `app/Http/Controllers/Api/V1/OrderReceiptController.php` (`ensureReceiptsFresh`)
- Test: `tests/Feature/Api/V1/Fiscal/OrderReceiptResourceTest.php`

**Interfaces:**
- Consumes: `PaymentReceipt.receipt_url`, `IntegrationConfigService::get('fiscal')`.
- Produces: `OrderReceiptResource` содержит `receiptUrl` (`receipt_url`); `seller` — из активных настроек `fiscal` (для `atol` — `credentials.atol.seller_name`/`inn`; иначе fallback на `config('services.yookassa.seller')`). `ensureReceiptsFresh` — для АТОЛ-`pending` чеков вызывает `PollAtolReceiptJob` синхронно (`dispatchSync`) для ленивой досинхронизации.

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Api/V1/Fiscal/OrderReceiptResourceTest.php — ключевой кейс
public function test_receipt_endpoint_exposes_receipt_url_and_seller(): void
{
    IntegrationConfig::create(['integration_type'=>'fiscal','driver'=>'atol','is_active'=>true,
        'credentials'=>['atol'=>['seller_name'=>'ООО Ромашка','inn'=>'7700000000']]]);
    // order+user (sanctum) + succeeded atol receipt с receipt_url
    $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts")
        ->assertOk()
        ->assertJsonPath('data.0.receiptUrl', 'http://ofd/x')
        ->assertJsonPath('data.0.seller.name', 'ООО Ромашка');
}
```

- [ ] **Step 2: Run — verify fails**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=OrderReceiptResourceTest`
Expected: FAIL.

- [ ] **Step 3: Modify resource** — добавить `'receiptUrl' => $this->receipt_url,` и заменить блок `seller` на резолв из `fiscal`-настроек (приватный helper `sellerInfo(): array` в ресурсе). 

- [ ] **Step 4: Modify controller** — в `ensureReceiptsFresh` для чеков `provider=atol, status=pending` вызвать `PollAtolReceiptJob::dispatchSync($receipt->id, 6)` (последняя попытка — синхронный опрос).

- [ ] **Step 5: Run — verify passes**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=OrderReceiptResourceTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Resources/Api/V1/OrderReceiptResource.php app/Http/Controllers/Api/V1/OrderReceiptController.php tests/Feature/Api/V1/Fiscal/OrderReceiptResourceTest.php
git commit -m "feat(fiscal): expose receiptUrl and provider-aware seller in receipts API"
```

---

### Task 13: Триггер возврата (refund) при драйвере АТОЛ

**Files:**
- Modify: `app/Services/Payment/PaymentService.php` (`handleRefundSucceeded` ~598-620)
- Test: `tests/Feature/Fiscal/RefundFiscalizationTriggerTest.php`

**Interfaces:**
- Produces: в `handleRefundSucceeded` при `getDriver('fiscal')==='atol'` — `FiscalizeRefundJob::dispatch($order->id, $amount)` вместо `receiptSync` (для yookassa — прежний `receiptSync`).

- [ ] **Step 1: Failing test** — `handleRefundSucceeded` при driver=atol пушит `FiscalizeRefundJob`; при yookassa — не пушит, зовёт sync (как раньше).
- [ ] **Step 2: Run fail** → `--filter=RefundFiscalizationTriggerTest`.
- [ ] **Step 3: Implement** — в `handleRefundSucceeded` обернуть вызов `receiptSync`/`FiscalizeRefundJob` условием по драйверу.
- [ ] **Step 4: Run pass.**
- [ ] **Step 5: Commit** `feat(fiscal): dispatch ATOL refund receipt on refund.succeeded`.

---

## Фаза 2 — Настройки в админке (admin_back)

> Репо `lastochka_admin_back`, ветка `feat/LASTOCHKI-JAMI-11651` от `master`. Тесты локально: `DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/Integrations/<File>.php`.

### Task 14: `FiscalIntegrationDescriptor` + регистрация

**Files:**
- Create: `app/Integrations/Descriptors/FiscalIntegrationDescriptor.php`
- Modify: `app/Integrations/IntegrationRegistry.php:5-11,23-33`
- Test: `tests/Feature/Integrations/FiscalDescriptorTest.php`

**Interfaces:**
- Produces: тип `fiscal` («Фискализация») в реестре; драйверы `atol` (полный набор кред-полей) и `yookassa` (без кред-полей — выбор + тестер); `getTester('atol')→AtolTester`, `getTester('yookassa')→YooKassaFiscalTester`.

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Integrations/FiscalDescriptorTest.php
<?php
namespace Tests\Feature\Integrations;
use App\Integrations\IntegrationRegistry;
use App\Integrations\Testers\AtolTester;
use App\Integrations\Testers\YooKassaFiscalTester;
use Tests\TestCase;

class FiscalDescriptorTest extends TestCase
{
    public function test_fiscal_type_registered_with_two_drivers(): void
    {
        $d = IntegrationRegistry::get('fiscal');
        $this->assertNotNull($d);
        $this->assertSame('fiscal', $d->type());
        $names = array_map(fn ($x) => $x->name, $d->drivers());
        $this->assertEqualsCanonicalizing(['atol', 'yookassa'], $names);
        $this->assertInstanceOf(AtolTester::class, $d->getTester('atol'));
        $this->assertInstanceOf(YooKassaFiscalTester::class, $d->getTester('yookassa'));
    }

    public function test_atol_driver_exposes_required_credential_fields(): void
    {
        $atol = IntegrationRegistry::get('fiscal')->getDriver('atol');
        $fields = array_map(fn ($f) => $f->field, $atol->credentialsSchema);
        foreach (['mode', 'login', 'password', 'group_code', 'inn', 'payment_address', 'sno', 'vat', 'company_email'] as $f) {
            $this->assertContains($f, $fields, "missing field {$f}");
        }
    }
}
```

- [ ] **Step 2: Run — verify fails**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/Integrations/FiscalDescriptorTest.php`
Expected: FAIL.

- [ ] **Step 3: Descriptor**

```php
// app/Integrations/Descriptors/FiscalIntegrationDescriptor.php
<?php
namespace App\Integrations\Descriptors;
use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\CredentialField;
use App\Integrations\DriverDescriptor;
use App\Integrations\IntegrationTypeDescriptor;
use App\Integrations\Testers\AtolTester;
use App\Integrations\Testers\YooKassaFiscalTester;

class FiscalIntegrationDescriptor extends IntegrationTypeDescriptor
{
    public function type(): string { return 'fiscal'; }
    public function label(): string { return 'Фискализация'; }

    public function drivers(): array
    {
        return [
            new DriverDescriptor('atol', 'АТОЛ Онлайн', [
                new CredentialField('mode', 'Режим работы', 'select', true, null, [
                    ['value' => 'test', 'label' => 'Тестовый'],
                    ['value' => 'live', 'label' => 'Боевой'],
                ]),
                new CredentialField('login', 'Логин API', 'text'),
                new CredentialField('password', 'Пароль API', 'password'),
                new CredentialField('group_code', 'Код группы касс', 'text'),
                new CredentialField('inn', 'ИНН продавца', 'text'),
                new CredentialField('payment_address', 'Адрес расчётов', 'text'),
                new CredentialField('company_email', 'Email кассы (для чека)', 'text'),
                new CredentialField('sno', 'Система налогообложения', 'select', false, null, [
                    ['value' => 'osn', 'label' => 'ОСН'],
                    ['value' => 'usn_income', 'label' => 'УСН доход'],
                    ['value' => 'usn_income_outcome', 'label' => 'УСН доход-расход'],
                    ['value' => 'envd', 'label' => 'ЕНВД'],
                    ['value' => 'esn', 'label' => 'ЕСХН'],
                    ['value' => 'patent', 'label' => 'Патент'],
                ]),
                new CredentialField('vat', 'Ставка НДС по умолчанию', 'select', false, null, [
                    ['value' => 'none', 'label' => 'Без НДС'],
                    ['value' => 'vat0', 'label' => 'НДС 0%'],
                    ['value' => 'vat10', 'label' => 'НДС 10%'],
                    ['value' => 'vat20', 'label' => 'НДС 20%'],
                    ['value' => 'vat110', 'label' => 'НДС 10/110'],
                    ['value' => 'vat120', 'label' => 'НДС 20/120'],
                ]),
                new CredentialField('payment_object', 'Признак предмета расчёта', 'select', false, null, [
                    ['value' => 'commodity', 'label' => 'Товар'],
                    ['value' => 'service', 'label' => 'Услуга'],
                ]),
                new CredentialField('seller_name', 'Название продавца (для чека)', 'text', false),
            ]),
            new DriverDescriptor('yookassa', 'ЮKassa (касса ЮKassa)'),
        ];
    }

    public function getTester(string $driverName): IntegrationTester
    {
        return match ($driverName) {
            'atol' => new AtolTester(),
            'yookassa' => new YooKassaFiscalTester(),
            default => throw new \InvalidArgumentException("Unknown fiscal driver: $driverName"),
        };
    }
}
```

- [ ] **Step 4: Register in IntegrationRegistry** — импорт `use App\Integrations\Descriptors\FiscalIntegrationDescriptor;` + строка `'fiscal' => new FiscalIntegrationDescriptor(),` в массиве `all()`.

- [ ] **Step 5: Run — verify passes** (тест выше). Реализуй заглушки `AtolTester`/`YooKassaFiscalTester` (implements `IntegrationTester`, `test()` возвращает `new TestResult(true)`), наполнишь в Задачах 15-16.

- [ ] **Step 6: Commit** `feat(fiscal): register 'fiscal' integration type with atol/yookassa drivers`.

---

### Task 15: `AtolTester`

**Files:**
- Modify: `app/Integrations/Testers/AtolTester.php`
- Test: `tests/Feature/Integrations/AtolTesterTest.php`

**Interfaces:**
- Produces: `AtolTester::test(array $creds, ?array, ?int): TestResult` — `getToken` по `login`/`password` на base по `mode`; успех токена → `TestResult(true, 'Авторизация успешна')`; пустой токен/ошибка → `TestResult(false, <текст ошибки АТОЛ>)`. Проверяет заполнение `login/password/group_code`.

- [ ] **Step 1: Failing test** (`Http::fake` getToken → token/ошибка). Кейсы: успех; неверные креды (`error.text`); пустые поля.
- [ ] **Step 2: Run fail** — `AtolTesterTest.php`.
- [ ] **Step 3: Implement** по образцу `YooKassaTester` (Http-фасад, ловит `ConnectionException`/`Throwable`, base_url по mode из `config`-аналога — в admin_back захардкодить `https://testonline.atol.ru/possystem/v4` / `https://online.atol.ru/possystem/v4`).
- [ ] **Step 4: Run pass.**
- [ ] **Step 5: Commit** `feat(fiscal): AtolTester (getToken connectivity check)`.

---

### Task 16: `YooKassaFiscalTester`

**Files:**
- Modify: `app/Integrations/Testers/YooKassaFiscalTester.php`
- Test: `tests/Feature/Integrations/YooKassaFiscalTesterTest.php`

**Interfaces:**
- Produces: `YooKassaFiscalTester::test()` проверяет по требованию задачи: (1) активна интеграция `payment` драйвер `yookassa` (`IntegrationConfig::where('integration_type','payment')->where('is_active',true)`), (2) `credentials.yookassa.mode === 'live'`, (3) касса включена — `GET https://api.yookassa.ru/v3/me` (live-креды) и `fiscalization_enabled === true` в ответе. Каждая непройденная проверка → `TestResult(false, <конкретное сообщение>)`; всё ок → `TestResult(true, 'Фискализация ЮKassa активна')`.

- [ ] **Step 1: Failing test** — кейсы: нет payment-интеграции («Подключите оплату ЮKassa»); `mode!=live` («Включите боевой режим»); `fiscalization_enabled=false` («Фискализация не активирована в кассе ЮKassa»); всё ок.
- [ ] **Step 2: Run fail** — `YooKassaFiscalTesterTest.php`.
- [ ] **Step 3: Implement** — читает payment-конфиг из БД, HTTP `GET /v3/me` (Http-фасад, BasicAuth live_shop_id/live_secret_key), проверяет `fiscalization_enabled`.
- [ ] **Step 4: Run pass.**
- [ ] **Step 5: Commit** `feat(fiscal): YooKassaFiscalTester (payment active + live + fiscalization enabled)`.

---

## Фаза 3 — Контракт мобилке

### Task 17: (опционально) подсказка-ссылка для типа `fiscal` в admin_front

YAGNI по умолчанию: карточка «Фискализация» появляется автоматически. Если нужна ссылка на инструкцию (как для `payment`) — добавить ветку `descriptor.type === 'fiscal'` в `IntegrationConfigModal.vue` по образцу `payment`. Не обязательна для работы фичи; включать только по явному запросу.

### Task 18: Документ API-контракта для мобильного разработчика

**Files:**
- Create: `docs/mobile/2026-07-09-fiscal-receipts-api-contract.md`

**Действие:** через скилл `api-contract-handoff` оформить контракт: `GET /api/v1/orders/{id}/receipts` (поля `type`, `status`, `registered_at`, `total`, `items`, `vat`, `fiscal{fn,fd,fp}`, `qr`, `receiptUrl`, `seller{name,inn}`) + формат FCM-пуша «Чек готов» (`data.type='receipt_ready'`, `order_id`, `action='open_order_details'`). Без бизнес-правил — только контракт ручек (по правилу проекта). Коммит `docs(fiscal): mobile API contract for receipts + push`.

---

## Фаза 4 — Интеграция, деплой, верификация

### Task 19: Полный прогон тестов и деплой

- [ ] **Step 1:** storefront_back — полный прогон новых групп: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=Fiscal` + регрессия `--filter=Payment`, `--filter=Receipt`, `--filter=Order`. Baseline-сверка через `git stash` (по образцу learnings) — 0 регрессий.
- [ ] **Step 2:** admin_back — `DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/Integrations/` по одному файлу.
- [ ] **Step 3:** По команде пользователя — мерж `--no-ff` feature-веток (storefront_back в `main`, admin_back в `master`), push; проверить CI обоих репо (`python3 ~/.claude/gitlab_cli.py last_pipeline <ns/repo> --ref <branch> --wait`, сверить sha).
- [ ] **Step 4:** После автодеплоя — на dev:
  - `sudo -u www-data php8.4 artisan route:clear` (storefront, новый роут `/api/v1/webhooks/atol`);
  - создать записи `integration_configs` тип `fiscal` в admin_back (через админку/SQL) — для АТОЛ с тестовыми кредами, `is_active`;
  - убедиться, что событие `integration_config.updated` доехало в storefront (`IntegrationConfigService::get('fiscal')` не пуст).

### Task 20: E2E на dev (реальные API-пути)

- [ ] **E2E-1 (онлайн, драйвер atol):** `POST /api/v1/orders` картой → capture → `payment.succeeded` webhook → `FiscalizeSaleJob` → АТОЛ (тестовый контур) → callback/poll → `GET /api/v1/orders/{id}/receipts` содержит `fiscal{fn,fd,fp}`, `qr`, `receiptUrl`; ЮKassa-чек НЕ пробит (проверить `send_receipt` off-эффект — в платеже нет receipt).
- [ ] **E2E-2 (наличные, драйвер atol):** заказ `cash` через `POST /api/v1/orders` → propagation в admin → перевод в `delivered` (курьерский путь/эмуляция события) → `order.updated delivered` в storefront → `is_paid=true` + `FiscalizeSaleJob(cash)` → чек в `/receipts`; повтор события `delivered` не создаёт второй чек.
- [ ] **E2E-3 (драйвер yookassa):** переключить `fiscal` на `yookassa` → онлайн-заказ → чек регистрируется ЮKassa (как раньше), `/receipts` отдаёт реквизиты; наличный заказ → independent receipt (`POST /receipts`) присутствует.
- [ ] **E2E-4 (тест-кнопка в админке):** `AtolTester` (валидные/битые креды) и `YooKassaFiscalTester` (payment off / mode=test / fiscalization off) через реальную мутацию `testIntegrationConfig` на dev.
- [ ] Верификация двусторонняя: (1) предусловие реально выполнено (заказ создан/доставлен через API), (2) результат корректен (чек в БД + в API + пуш ушёл). Проверять через MySQL (`payment_receipts`) и curl `/receipts`.

---

## Self-Review

**Spec coverage:**
- Драйвер АТОЛ по API (нал+онлайн) → Задачи 3-6, 8-10. ✓
- Выбор фискализатора в админке + «Проверить подключение» → Задачи 14-16. ✓
- Не двойной чек → Задача 7 (ЮKassa-чек off при atol) + Задача 8 (единый источник). ✓
- Наличные по «Доставлен» → Задача 9. ✓
- Хранение fiscal-идентификаторов → Задачи 1, 6, 10. ✓
- Мобилка: показ чека + пуш «Чек готов» → Задачи 11, 12, 18. ✓
- Ошибка → Sentry + ретрай через очередь → Задача 6 (`failed()`, backoff, PollAtolReceiptJob). ✓
- ЮKassa-тестер: payment active + live + фискализация вкл → Задача 16. ✓

**Placeholder scan:** пометки «реализатор подтверждает имена колонок Order/OrderItem» (Задача 4), «фикстуру по образцу» (Задачи 8, 9) — это указания на существующие проверяемые паттерны, не пропуски логики; каждый шаг несёт конкретный код/команду. Задача 17 явно помечена YAGNI/опциональной.

**Type consistency:** `FiscalReceiptResult` (provider/externalId/status/fn/fd/fp/receiptUrl) единообразно во всех задачах; `Fiscalizer::fiscalizeSale/Refund` сигнатуры совпадают в контракте, фейке, обеих реализациях и job'ах; `ReceiptProvider::{Yookassa,Atol}` и dedup-ключ `(provider, external_id)` согласованы в Задачах 1, 4, 5, 10.

**Открытый вопрос на подтверждение (вынесен в Global Constraints):** фискальные поля ЮKassa НЕ переносятся из типа `payment` в `fiscal` (обратная совместимость) — отступление от блока 2 спеки.
