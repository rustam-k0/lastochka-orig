# Чеки заказа (ЮKassa Receipts) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дать покупателю в мобильном приложении эндпоинт со списком фискальных чеков его заказа (приходы + возвраты) — позиции, НДС, фискальные реквизиты, QR-код для проверки в ФНС.

**Architecture:** Чеки тянутся из ЮKassa по уже существующему вебхуку (`payment.succeeded`/`refund.succeeded`) и сохраняются в новую таблицу `payment_receipts`. Клиент читает быстро из нашей БД; если вебхук опоздал — синхронный fallback-запрос к ЮKassa при обращении. QR-строка 54-ФЗ и реквизиты продавца собираются на бэке.

**Tech Stack:** PHP 8.4, Laravel 12, PHPUnit, `Http::fake` для ЮKassa, паттерн `PaymentGateway` (интерфейс + `FakePaymentGateway` в тестах).

---

## Предварительно

- Ветка: вся работа в одной feature-ветке (например `feat/order-receipts`), коммиты только туда, не в `master`. Мобилка `aladdin_serverpod_mobile` — read-only, её не трогаем (только описываем контракт).
- Команда тестов (важно — отдельный PHP 8.4, иначе `platform_check`/OOM):
  ```
  /opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=<TestName>
  ```
- Все пути ниже относительно `aladdin_storefront_back/`.

## Карта файлов

**Создаём:**
- `database/migrations/2026_06_16_120000_create_payment_receipts_table.php` — таблица чеков
- `app/Enums/PaymentReceiptType.php` — enum `payment|refund`
- `app/Models/PaymentReceipt.php` — модель чека
- `app/Services/Payment/ReceiptSyncService.php` — синк чеков из ЮKassa в БД
- `app/Services/Payment/ReceiptQrBuilder.php` — сборка QR-строки 54-ФЗ
- `app/Http/Resources/Api/V1/OrderReceiptResource.php` — сериализация чека
- `app/Http/Controllers/Api/V1/OrderReceiptController.php` — эндпоинт
- `tests/Unit/Services/Payment/ReceiptQrBuilderTest.php`
- `tests/Unit/Services/Payment/ReceiptSyncServiceTest.php`
- `tests/Feature/Api/V1/Order/OrderReceiptsTest.php`

**Изменяем:**
- `app/Contracts/PaymentGateway.php` — метод `getReceipts`
- `app/Services/Payment/YooKassaService.php` — реализация `getReceipts`
- `tests/Fakes/Payment/FakePaymentGateway.php` — реализация `getReceipts`
- `app/Services/Payment/PaymentService.php` — ctor + вызовы синка в вебхук-хендлерах
- `app/Models/Order.php` — relation `receipts()`
- `config/services.php` — `yookassa.seller`
- `routes/api.php` — маршрут `GET v1/orders/{order}/receipts`

Статус (`pending|succeeded|canceled`) переиспользуем из существующего `App\Enums\PaymentTransactionStatus` (значения совпадают). Новый enum нужен только для типа чека.

---

### Task 1: Таблица `payment_receipts`, enum типа и модель

**Files:**
- Create: `app/Enums/PaymentReceiptType.php`
- Create: `database/migrations/2026_06_16_120000_create_payment_receipts_table.php`
- Create: `app/Models/PaymentReceipt.php`
- Modify: `app/Models/Order.php` (добавить relation `receipts()`)
- Test: `tests/Unit/Services/Payment/ReceiptSyncServiceTest.php` (smoke на модель появится в Task 3; здесь — миграция через RefreshDatabase в любом тесте)

- [ ] **Step 1: Создать enum типа чека**

`app/Enums/PaymentReceiptType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentReceiptType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
}
```

- [ ] **Step 2: Создать миграцию**

`database/migrations/2026_06_16_120000_create_payment_receipts_table.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('yookassa_receipt_id')->unique();
            $table->string('type'); // payment | refund
            $table->string('status'); // pending | succeeded | canceled
            $table->string('fiscal_storage_number')->nullable();   // fn
            $table->string('fiscal_document_number')->nullable();  // fd
            $table->string('fiscal_attribute')->nullable();        // fp
            $table->dateTime('registered_at')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->json('items');
            $table->json('raw');
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
```

- [ ] **Step 3: Создать модель**

`app/Models/PaymentReceipt.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentReceiptType;
use App\Enums\PaymentTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentReceipt extends Model
{
    protected $fillable = [
        'order_id',
        'payment_transaction_id',
        'yookassa_receipt_id',
        'type',
        'status',
        'fiscal_storage_number',
        'fiscal_document_number',
        'fiscal_attribute',
        'registered_at',
        'amount',
        'items',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentReceiptType::class,
            'status' => PaymentTransactionStatus::class,
            'registered_at' => 'datetime',
            'amount' => 'decimal:2',
            'items' => 'array',
            'raw' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
```

- [ ] **Step 4: Добавить relation в Order**

В `app/Models/Order.php` рядом с существующим `paymentTransactions()` (около строки 128) добавить:
```php
    public function receipts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }
```
(Если в начале файла уже импортирован `Illuminate\Database\Eloquent\Relations\HasMany` — использовать короткое `HasMany` и не дублировать FQN.)

- [ ] **Step 5: Прогнать миграцию в тест-окружении (sanity)**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan migrate --pretend`
Expected: в выводе присутствует `create table "payment_receipts"`, без ошибок синтаксиса.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/PaymentReceiptType.php database/migrations/2026_06_16_120000_create_payment_receipts_table.php app/Models/PaymentReceipt.php app/Models/Order.php
git commit -m "feat: payment_receipts table, model and order relation"
```

---

### Task 2: `getReceipts` в гейтвее (интерфейс + YooKassa + Fake)

**Files:**
- Modify: `app/Contracts/PaymentGateway.php`
- Modify: `app/Services/Payment/YooKassaService.php`
- Modify: `tests/Fakes/Payment/FakePaymentGateway.php`
- Test: `tests/Unit/Services/Payment/ReceiptSyncServiceTest.php` (косвенно в Task 3); прямой тест маппинга — в Task 3

- [ ] **Step 1: Добавить метод в интерфейс**

В `app/Contracts/PaymentGateway.php` после `getPaymentInfo` добавить:
```php
    /**
     * Получить чеки (54-ФЗ) по платежу или возврату.
     * Передаётся ровно один из аргументов. Возвращает список «сырых»
     * объектов чека ЮKassa (как массивы). Пустой массив — чеков нет или ошибка.
     *
     * @return list<array<string, mixed>>
     */
    public function getReceipts(?string $paymentId = null, ?string $refundId = null): array;
```

- [ ] **Step 2: Реализовать в YooKassaService**

В `app/Services/Payment/YooKassaService.php` после `getPaymentInfo` (перед `getCredentials`) добавить:
```php
    public function getReceipts(?string $paymentId = null, ?string $refundId = null): array
    {
        $query = [];
        if ($paymentId !== null && $paymentId !== '') {
            $query['payment_id'] = $paymentId;
        }
        if ($refundId !== null && $refundId !== '') {
            $query['refund_id'] = $refundId;
        }
        if ($query === []) {
            return [];
        }

        $receipts = [];
        $cursor = null;

        do {
            $params = $query;
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }

            $response = $this->request('GET', '/receipts?' . http_build_query($params));

            if (!$response->successful()) {
                Log::error('YooKassa getReceipts failed', [
                    'query' => $query,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                break;
            }

            $data = $response->json();
            foreach ($data['items'] ?? [] as $item) {
                $receipts[] = $item;
            }

            $cursor = $data['next_cursor'] ?? null;
        } while ($cursor !== null);

        return $receipts;
    }
```

- [ ] **Step 3: Реализовать в FakePaymentGateway**

В `tests/Fakes/Payment/FakePaymentGateway.php` добавить публичные свойства рядом с `$paymentInfo`:
```php
    /** @var array<string, list<array<string, mixed>>> */
    public array $receiptsByPaymentId = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $receiptsByRefundId = [];
```
и метод (рядом с `getPaymentInfo`):
```php
    public function getReceipts(?string $paymentId = null, ?string $refundId = null): array
    {
        $this->calls[] = [
            'method' => 'getReceipts',
            'args' => ['paymentId' => $paymentId, 'refundId' => $refundId],
        ];

        if ($paymentId !== null) {
            return $this->receiptsByPaymentId[$paymentId] ?? [];
        }
        if ($refundId !== null) {
            return $this->receiptsByRefundId[$refundId] ?? [];
        }

        return [];
    }
```

- [ ] **Step 4: Прогнать существующие платёжные тесты — контракт интерфейса не сломан**

Run: `/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=ClientOrderPaymentTest`
Expected: PASS (FakePaymentGateway теперь реализует полный интерфейс).

- [ ] **Step 5: Commit**

```bash
git add app/Contracts/PaymentGateway.php app/Services/Payment/YooKassaService.php tests/Fakes/Payment/FakePaymentGateway.php
git commit -m "feat: getReceipts on payment gateway (yookassa + fake)"
```

---

### Task 3: `ReceiptSyncService` — синк чеков ЮKassa → БД

**Files:**
- Create: `app/Services/Payment/ReceiptSyncService.php`
- Test: `tests/Unit/Services/Payment/ReceiptSyncServiceTest.php`

- [ ] **Step 1: Написать падающий тест**

`tests/Unit/Services/Payment/ReceiptSyncServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Enums\PaymentReceiptType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Services\Payment\ReceiptSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

final class ReceiptSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function rawPaymentReceipt(string $paymentId): array
    {
        return [
            'id' => 'rt-test-1',
            'type' => 'payment',
            'payment_id' => $paymentId,
            'status' => 'succeeded',
            'fiscal_document_number' => '3986',
            'fiscal_storage_number' => '9288000100115785',
            'fiscal_attribute' => '2617603921',
            'registered_at' => '2026-06-16T15:42:00.000Z',
            'items' => [
                ['description' => 'Молоко 3.2% 1л', 'quantity' => '1.00', 'amount' => ['value' => '600.00', 'currency' => 'RUB'], 'vat_code' => 4],
                ['description' => 'Доставка', 'quantity' => '1.00', 'amount' => ['value' => '414.00', 'currency' => 'RUB'], 'vat_code' => 4],
            ],
            'settlements' => [
                ['type' => 'cashless', 'amount' => ['value' => '1014.00', 'currency' => 'RUB']],
            ],
        ];
    }

    public function test_sync_creates_receipt_from_payment_transaction(): void
    {
        $order = Order::factory()->create();
        $tx = PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 1014.00,
            'yookassa_payment_id' => 'pay_1',
        ]);

        $gateway = new FakePaymentGateway();
        $gateway->receiptsByPaymentId['pay_1'] = [$this->rawPaymentReceipt('pay_1')];

        (new ReceiptSyncService($gateway))->syncForTransaction($tx);

        $receipt = PaymentReceipt::where('yookassa_receipt_id', 'rt-test-1')->firstOrFail();
        $this->assertSame($order->id, $receipt->order_id);
        $this->assertSame($tx->id, $receipt->payment_transaction_id);
        $this->assertSame(PaymentReceiptType::Payment, $receipt->type);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $receipt->status);
        $this->assertSame('9288000100115785', $receipt->fiscal_storage_number);
        $this->assertSame('3986', $receipt->fiscal_document_number);
        $this->assertSame('2617603921', $receipt->fiscal_attribute);
        $this->assertSame('1014.00', $receipt->amount);
        $this->assertCount(2, $receipt->items);
        $this->assertSame('Молоко 3.2% 1л', $receipt->items[0]['description']);
        $this->assertSame('600.00', $receipt->items[0]['amount']);
        $this->assertSame(4, $receipt->items[0]['vat_code']);
    }

    public function test_sync_is_idempotent_by_receipt_id(): void
    {
        $order = Order::factory()->create();
        $tx = PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 1014.00,
            'yookassa_payment_id' => 'pay_1',
        ]);

        $gateway = new FakePaymentGateway();
        $gateway->receiptsByPaymentId['pay_1'] = [$this->rawPaymentReceipt('pay_1')];

        $service = new ReceiptSyncService($gateway);
        $service->syncForTransaction($tx);
        $service->syncForTransaction($tx);

        $this->assertSame(1, PaymentReceipt::where('yookassa_receipt_id', 'rt-test-1')->count());
    }

    public function test_sync_refund_transaction_uses_refund_id(): void
    {
        $order = Order::factory()->create();
        $tx = PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Refund,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 200.00,
            'yookassa_payment_id' => 'pay_1',
            'yookassa_refund_id' => 'ref_1',
        ]);

        $gateway = new FakePaymentGateway();
        $gateway->receiptsByRefundId['ref_1'] = [[
            'id' => 'rt-refund-1',
            'type' => 'refund',
            'refund_id' => 'ref_1',
            'status' => 'succeeded',
            'fiscal_document_number' => '3990',
            'fiscal_storage_number' => '9288000100115785',
            'fiscal_attribute' => '111',
            'registered_at' => '2026-06-16T16:00:00.000Z',
            'items' => [['description' => 'Возврат', 'quantity' => '1.00', 'amount' => ['value' => '200.00'], 'vat_code' => 4]],
            'settlements' => [['type' => 'cashless', 'amount' => ['value' => '200.00']]],
        ]];

        (new ReceiptSyncService($gateway))->syncForTransaction($tx);

        $receipt = PaymentReceipt::where('yookassa_receipt_id', 'rt-refund-1')->firstOrFail();
        $this->assertSame(PaymentReceiptType::Refund, $receipt->type);
        $this->assertSame('200.00', $receipt->amount);
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=ReceiptSyncServiceTest`
Expected: FAIL — `Class "App\Services\Payment\ReceiptSyncService" not found`.

- [ ] **Step 3: Реализовать сервис**

`app/Services/Payment/ReceiptSyncService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionType;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use Illuminate\Support\Carbon;

final class ReceiptSyncService
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * Синхронизировать чеки одной транзакции из ЮKassa в БД.
     */
    public function syncForTransaction(PaymentTransaction $transaction): void
    {
        if ($transaction->type === PaymentTransactionType::Refund) {
            if (! $transaction->yookassa_refund_id) {
                return;
            }
            $raw = $this->gateway->getReceipts(refundId: $transaction->yookassa_refund_id);
        } else {
            if (! $transaction->yookassa_payment_id) {
                return;
            }
            $raw = $this->gateway->getReceipts(paymentId: $transaction->yookassa_payment_id);
        }

        foreach ($raw as $item) {
            $this->upsert($transaction, $item);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsert(PaymentTransaction $transaction, array $item): void
    {
        $receiptId = $item['id'] ?? null;
        if ($receiptId === null) {
            return;
        }

        PaymentReceipt::updateOrCreate(
            ['yookassa_receipt_id' => $receiptId],
            [
                'order_id' => $transaction->order_id,
                'payment_transaction_id' => $transaction->id,
                'type' => $item['type'] ?? 'payment',
                'status' => $item['status'] ?? 'pending',
                'fiscal_storage_number' => $item['fiscal_storage_number'] ?? null,
                'fiscal_document_number' => $item['fiscal_document_number'] ?? null,
                'fiscal_attribute' => $item['fiscal_attribute'] ?? null,
                'registered_at' => isset($item['registered_at'])
                    ? Carbon::parse($item['registered_at'])
                    : null,
                'amount' => $this->resolveAmount($item),
                'items' => $this->mapItems($item['items'] ?? []),
                'raw' => $item,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveAmount(array $item): float
    {
        $settlements = $item['settlements'] ?? [];
        if ($settlements !== []) {
            return array_reduce(
                $settlements,
                static fn (float $carry, array $s): float => $carry + (float) ($s['amount']['value'] ?? 0),
                0.0,
            );
        }

        return array_reduce(
            $item['items'] ?? [],
            static fn (float $carry, array $i): float =>
                $carry + (float) ($i['amount']['value'] ?? 0) * (float) ($i['quantity'] ?? 1),
            0.0,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function mapItems(array $items): array
    {
        return array_map(static fn (array $i): array => [
            'description' => $i['description'] ?? '',
            'quantity' => (string) ($i['quantity'] ?? '1.00'),
            'amount' => (string) ($i['amount']['value'] ?? '0.00'),
            'vat_code' => $i['vat_code'] ?? null,
        ], $items);
    }
}
```

- [ ] **Step 4: Запустить — убедиться, что проходит**

Run: `/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=ReceiptSyncServiceTest`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payment/ReceiptSyncService.php tests/Unit/Services/Payment/ReceiptSyncServiceTest.php
git commit -m "feat: ReceiptSyncService syncs yookassa receipts into db"
```

---

### Task 4: `ReceiptQrBuilder` — QR-строка 54-ФЗ

**Files:**
- Create: `app/Services/Payment/ReceiptQrBuilder.php`
- Test: `tests/Unit/Services/Payment/ReceiptQrBuilderTest.php`

- [ ] **Step 1: Написать падающий тест**

`tests/Unit/Services/Payment/ReceiptQrBuilderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Enums\PaymentReceiptType;
use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentReceipt;
use App\Services\Payment\ReceiptQrBuilder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ReceiptQrBuilderTest extends TestCase
{
    private function receipt(array $overrides = []): PaymentReceipt
    {
        return (new PaymentReceipt())->forceFill(array_merge([
            'type' => PaymentReceiptType::Payment,
            'status' => PaymentTransactionStatus::Succeeded,
            'fiscal_storage_number' => '9288000100115785',
            'fiscal_document_number' => '3986',
            'fiscal_attribute' => '2617603921',
            'registered_at' => Carbon::parse('2026-06-16T15:42:00+03:00'),
            'amount' => '1014.00',
        ], $overrides));
    }

    public function test_builds_qr_for_payment_receipt(): void
    {
        $qr = (new ReceiptQrBuilder())->build($this->receipt());

        $this->assertSame(
            't=20260616T1542&s=1014.00&fn=9288000100115785&i=3986&fp=2617603921&n=1',
            $qr,
        );
    }

    public function test_refund_receipt_uses_n_2(): void
    {
        $qr = (new ReceiptQrBuilder())->build($this->receipt(['type' => PaymentReceiptType::Refund]));

        $this->assertStringEndsWith('&n=2', $qr);
    }

    public function test_returns_null_when_pending(): void
    {
        $qr = (new ReceiptQrBuilder())->build($this->receipt(['status' => PaymentTransactionStatus::Pending]));

        $this->assertNull($qr);
    }

    public function test_returns_null_when_fiscal_attributes_missing(): void
    {
        $qr = (new ReceiptQrBuilder())->build($this->receipt(['fiscal_attribute' => null]));

        $this->assertNull($qr);
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=ReceiptQrBuilderTest`
Expected: FAIL — `Class "App\Services\Payment\ReceiptQrBuilder" not found`.

- [ ] **Step 3: Реализовать билдер**

`app/Services/Payment/ReceiptQrBuilder.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentReceiptType;
use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentReceipt;

final class ReceiptQrBuilder
{
    /**
     * Строка стандарта 54-ФЗ для QR-кода чека.
     * Возвращает null, если чек не фискализирован (нет реквизитов).
     */
    public function build(PaymentReceipt $receipt): ?string
    {
        if ($receipt->status !== PaymentTransactionStatus::Succeeded) {
            return null;
        }

        if (
            ! $receipt->fiscal_storage_number
            || ! $receipt->fiscal_document_number
            || ! $receipt->fiscal_attribute
            || $receipt->registered_at === null
        ) {
            return null;
        }

        $t = $receipt->registered_at->format('Ymd\THi');
        $s = number_format((float) $receipt->amount, 2, '.', '');
        $n = $receipt->type === PaymentReceiptType::Refund ? 2 : 1;

        return "t={$t}&s={$s}"
            . "&fn={$receipt->fiscal_storage_number}"
            . "&i={$receipt->fiscal_document_number}"
            . "&fp={$receipt->fiscal_attribute}"
            . "&n={$n}";
    }
}
```

- [ ] **Step 4: Запустить — убедиться, что проходит**

Run: `/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=ReceiptQrBuilderTest`
Expected: PASS (4 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payment/ReceiptQrBuilder.php tests/Unit/Services/Payment/ReceiptQrBuilderTest.php
git commit -m "feat: ReceiptQrBuilder builds 54-fz qr string"
```

---

### Task 5: Реквизиты продавца в конфиге

**Files:**
- Modify: `config/services.php`

- [ ] **Step 1: Добавить блок seller**

В `config/services.php` внутри массива `'yookassa' => [ ... ]` (после `payment_subject`, перед закрывающей `],`) добавить:
```php
        // Реквизиты продавца для отображения на чеке в приложении.
        // ЮKassa их в GET /receipts не возвращает — берём из настроек.
        'seller' => [
            'name' => env('YOOKASSA_SELLER_NAME'),
            'inn' => env('YOOKASSA_SELLER_INN'),
        ],
```

- [ ] **Step 2: Проверить, что конфиг читается**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan tinker --execute="echo json_encode(config('services.yookassa.seller'));"`
Expected: `{"name":null,"inn":null}` (env не задан — это норма).

- [ ] **Step 3: Commit**

```bash
git add config/services.php
git commit -m "feat: yookassa seller config for receipts"
```

---

### Task 6: `OrderReceiptResource` — сериализация чека

**Files:**
- Create: `app/Http/Resources/Api/V1/OrderReceiptResource.php`
- Test: покрывается feature-тестом в Task 8

- [ ] **Step 1: Реализовать ресурс**

`app/Http/Resources/Api/V1/OrderReceiptResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\PaymentTransactionStatus;
use App\Services\Payment\ReceiptQrBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PaymentReceipt
 */
final class OrderReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isFiscalized = $this->status === PaymentTransactionStatus::Succeeded
            && $this->fiscal_storage_number !== null;

        return [
            'type' => $this->type->value,
            'status' => $this->status->value,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'total' => number_format((float) $this->amount, 2, '.', ''),
            'items' => $this->items,
            'fiscal' => $isFiscalized ? [
                'fn' => $this->fiscal_storage_number,
                'fd' => $this->fiscal_document_number,
                'fp' => $this->fiscal_attribute,
            ] : null,
            'qr' => app(ReceiptQrBuilder::class)->build($this->resource),
            'seller' => [
                'name' => config('services.yookassa.seller.name'),
                'inn' => config('services.yookassa.seller.inn'),
            ],
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Resources/Api/V1/OrderReceiptResource.php
git commit -m "feat: OrderReceiptResource"
```

---

### Task 7: Контроллер + маршрут (с fallback-синком)

**Files:**
- Create: `app/Http/Controllers/Api/V1/OrderReceiptController.php`
- Modify: `routes/api.php`
- Test: feature-тест в Task 8

- [ ] **Step 1: Реализовать контроллер**

`app/Http/Controllers/Api/V1/OrderReceiptController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderReceiptResource;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payment\ReceiptSyncService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Orders', weight: 10)]
final class OrderReceiptController extends Controller
{
    public function __construct(private ReceiptSyncService $receiptSync) {}

    /**
     * Список фискальных чеков заказа (приходы и возвраты).
     */
    public function index(Order $order): AnonymousResourceCollection
    {
        $this->authorize('view', $order);

        $this->ensureReceiptsFresh($order);

        $receipts = $order->receipts()
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->get();

        return OrderReceiptResource::collection($receipts);
    }

    /**
     * Если по успешной транзакции чека ещё нет в БД (вебхук опоздал/сбой) —
     * синхронно дотянуть его из ЮKassa перед ответом.
     */
    private function ensureReceiptsFresh(Order $order): void
    {
        $transactions = $order->paymentTransactions()
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->get();

        $syncedTransactionIds = $order->receipts()
            ->whereNotNull('payment_transaction_id')
            ->pluck('payment_transaction_id')
            ->all();

        $transactions
            ->reject(fn (PaymentTransaction $tx) => in_array($tx->id, $syncedTransactionIds, true))
            ->each(fn (PaymentTransaction $tx) => $this->receiptSync->syncForTransaction($tx));
    }
}
```

- [ ] **Step 2: Зарегистрировать маршрут**

В `routes/api.php`, внутри группы `Route::middleware('auth:sanctum')->prefix('orders')->group(...)` (около строки 257), рядом с `Route::get('/{order}', ...)` добавить:
```php
        Route::get('/{order}/receipts', [\App\Http\Controllers\Api\V1\OrderReceiptController::class, 'index']);
```

- [ ] **Step 3: Проверить, что маршрут зарегистрирован**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan route:list --path=orders | grep receipts`
Expected: строка `GET|HEAD  api/v1/orders/{order}/receipts ... OrderReceiptController@index`.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/V1/OrderReceiptController.php routes/api.php
git commit -m "feat: GET orders/{order}/receipts endpoint with lazy fallback"
```

---

### Task 8: Feature-тесты эндпоинта

**Files:**
- Create: `tests/Feature/Api/V1/Order/OrderReceiptsTest.php`

- [ ] **Step 1: Написать тесты**

`tests/Feature/Api/V1/Order/OrderReceiptsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

final class OrderReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakePaymentGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function succeededPaymentRaw(string $paymentId, string $receiptId): array
    {
        return [
            'id' => $receiptId,
            'type' => 'payment',
            'payment_id' => $paymentId,
            'status' => 'succeeded',
            'fiscal_document_number' => '3986',
            'fiscal_storage_number' => '9288000100115785',
            'fiscal_attribute' => '2617603921',
            'registered_at' => '2026-06-16T15:42:00.000Z',
            'items' => [
                ['description' => 'Молоко 3.2% 1л', 'quantity' => '1.00', 'amount' => ['value' => '600.00'], 'vat_code' => 4],
            ],
            'settlements' => [['type' => 'cashless', 'amount' => ['value' => '600.00']]],
        ];
    }

    public function test_returns_receipt_with_items_fiscal_and_qr(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        $tx = PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_1',
        ]);
        $this->gateway->receiptsByPaymentId['pay_1'] = [$this->succeededPaymentRaw('pay_1', 'rt-1')];

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'payment')
            ->assertJsonPath('data.0.status', 'succeeded')
            ->assertJsonPath('data.0.total', '600.00')
            ->assertJsonPath('data.0.fiscal.fn', '9288000100115785')
            ->assertJsonPath('data.0.fiscal.fd', '3986')
            ->assertJsonPath('data.0.items.0.description', 'Молоко 3.2% 1л');

        $this->assertStringContainsString('fn=9288000100115785', $response->json('data.0.qr'));
        $this->assertStringEndsWith('&n=1', $response->json('data.0.qr'));
        unset($tx);
    }

    public function test_lazy_fallback_persists_receipt(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_1',
        ]);
        $this->gateway->receiptsByPaymentId['pay_1'] = [$this->succeededPaymentRaw('pay_1', 'rt-1')];

        $this->assertSame(0, PaymentReceipt::count());

        $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts")->assertOk();

        $this->assertSame(1, PaymentReceipt::where('yookassa_receipt_id', 'rt-1')->count());
    }

    public function test_pending_receipt_has_null_fiscal_and_qr(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_1',
        ]);
        $this->gateway->receiptsByPaymentId['pay_1'] = [[
            'id' => 'rt-pending',
            'type' => 'payment',
            'payment_id' => 'pay_1',
            'status' => 'pending',
            'items' => [['description' => 'Товар', 'quantity' => '1.00', 'amount' => ['value' => '600.00'], 'vat_code' => 4]],
            'settlements' => [['type' => 'cashless', 'amount' => ['value' => '600.00']]],
        ]];

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.fiscal', null)
            ->assertJsonPath('data.0.qr', null)
            ->assertJsonPath('data.0.items.0.description', 'Товар');
    }

    public function test_payment_and_refund_return_two_receipts(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_1',
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Refund,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 200.00,
            'yookassa_payment_id' => 'pay_1',
            'yookassa_refund_id' => 'ref_1',
        ]);
        $this->gateway->receiptsByPaymentId['pay_1'] = [$this->succeededPaymentRaw('pay_1', 'rt-1')];
        $this->gateway->receiptsByRefundId['ref_1'] = [[
            'id' => 'rt-refund',
            'type' => 'refund',
            'refund_id' => 'ref_1',
            'status' => 'succeeded',
            'fiscal_document_number' => '3990',
            'fiscal_storage_number' => '9288000100115785',
            'fiscal_attribute' => '111',
            'registered_at' => '2026-06-16T16:00:00.000Z',
            'items' => [['description' => 'Возврат', 'quantity' => '1.00', 'amount' => ['value' => '200.00'], 'vat_code' => 4]],
            'settlements' => [['type' => 'cashless', 'amount' => ['value' => '200.00']]],
        ]];

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()->assertJsonCount(2, 'data');
        $types = collect($response->json('data'))->pluck('type')->all();
        $this->assertContains('payment', $types);
        $this->assertContains('refund', $types);
    }

    public function test_other_users_order_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $order = Order::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->getJson("/api/v1/orders/{$order->id}/receipts")
            ->assertForbidden();
    }

    public function test_order_without_payments_returns_empty(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson("/api/v1/orders/{$order->id}/receipts")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
```

- [ ] **Step 2: Запустить — все сценарии зелёные**

Run: `/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=OrderReceiptsTest`
Expected: PASS (6 тестов).

> Если `test_other_users_order_is_forbidden` падает с 404 вместо 403 — проверить, что у модели `Order` нет глобального скоупа по пользователю; policy `view` уже существует и привязана через автоматический resolver `authorize('view', $order)`. Если в проекте policies не автодискаверятся — зарегистрировать `OrderPolicy` в `AppServiceProvider::boot()` через `Gate::policy(Order::class, OrderPolicy::class)`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Api/V1/Order/OrderReceiptsTest.php
git commit -m "test: feature tests for order receipts endpoint"
```

---

### Task 9: Подключить синк к вебхуку оплаты/возврата

**Files:**
- Modify: `app/Services/Payment/PaymentService.php`
- Test: `tests/Feature/Api/V1/Order/OrderReceiptsTest.php` (добавить webhook-сценарий)

- [ ] **Step 1: Написать падающий тест на синк по вебхуку**

Добавить в `tests/Feature/Api/V1/Order/OrderReceiptsTest.php` метод:
```php
    public function test_webhook_payment_succeeded_syncs_receipt(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create(['is_paid' => false]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_hook',
        ]);
        $this->gateway->receiptsByPaymentId['pay_hook'] = [$this->succeededPaymentRaw('pay_hook', 'rt-hook')];

        $this->postJson('/api/v1/webhooks/yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'pay_hook'],
        ])->assertOk();

        $this->assertDatabaseHas('payment_receipts', [
            'yookassa_receipt_id' => 'rt-hook',
            'order_id' => $order->id,
        ]);
    }
```
(Webhook в `local`/`testing` проходит IP-проверку — `isYooKassaIp` возвращает true для этих окружений.)

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=test_webhook_payment_succeeded_syncs_receipt`
Expected: FAIL — таблица `payment_receipts` пуста (синк ещё не подключён к хендлеру).

- [ ] **Step 3: Внедрить ReceiptSyncService в PaymentService**

В `app/Services/Payment/PaymentService.php`:

3a. Добавить импорт рядом с другими `use` (после `use App\Models\User;`):
```php
use App\Services\Payment\ReceiptSyncService;
```
(Если PaymentService уже в namespace `App\Services\Payment` — импорт не нужен, класс в том же namespace; использовать просто `ReceiptSyncService`.)

3b. Расширить конструктор:
```php
    public function __construct(
        private PaymentGateway $gateway,
        private ReceiptBuilder $receiptBuilder,
        private ReceiptSyncService $receiptSync,
    ) {}
```

3c. В `handlePaymentSucceeded`, внутри `DB::transaction(...)` сразу после `$this->publishOrderPaid($order);` добавить синк по созданной capture-транзакции. Заменить хвост колбэка так, чтобы захватить созданную транзакцию:
```php
            $captureTransaction = PaymentTransaction::create([
                'order_id' => $order->id,
                'type' => PaymentTransactionType::Capture,
                'status' => PaymentTransactionStatus::Succeeded,
                'amount' => $amount,
                'yookassa_payment_id' => $yookassaPaymentId,
            ]);

            $order->update([
                'captured_amount' => $amount,
                'is_paid' => true,
            ]);

            $this->publishOrderPaid($order);

            $this->receiptSync->syncForTransaction($captureTransaction);
```

3d. В `handleRefundSucceeded`, внутри `DB::transaction(...)`, после блока обновления `$transaction` добавить синк (когда транзакция найдена):
```php
            if ($transaction) {
                $transaction->update(['status' => PaymentTransactionStatus::Succeeded]);
                $this->receiptSync->syncForTransaction($transaction);
            }
```
(Это заменяет существующий `if ($transaction) { $transaction->update(...); }` — добавляется только строка синка.)

- [ ] **Step 4: Запустить webhook-тест и весь набор чеков**

Run: `/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=OrderReceiptsTest`
Expected: PASS (7 тестов, включая webhook).

- [ ] **Step 5: Регрессия платёжного флоу — ctor PaymentService не сломал существующее**

Run: `/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit --filter=ClientOrderPaymentTest`
Expected: PASS (Laravel сам резолвит новый аргумент ctor через контейнер; `ReceiptSyncService` зависит от `PaymentGateway`, который в тестах подменён на Fake).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payment/PaymentService.php tests/Feature/Api/V1/Order/OrderReceiptsTest.php
git commit -m "feat: sync receipts on payment.succeeded and refund.succeeded webhooks"
```

---

### Task 10: Финальная регрессия и проверка контракта

- [ ] **Step 1: Прогнать все новые + смежные тесты**

Run:
```
/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=2G vendor/bin/phpunit \
  --filter='ReceiptQrBuilderTest|ReceiptSyncServiceTest|OrderReceiptsTest|ClientOrderPaymentTest'
```
Expected: PASS, без новых ошибок относительно baseline `main`.

- [ ] **Step 2: Проверить OpenAPI/Scramble не падает**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan route:list --path=orders | grep receipts`
Expected: маршрут на месте.

- [ ] **Step 3: Финальный commit (если остались несведённые изменения)**

```bash
git add -A
git commit -m "chore: order receipts feature finalize" || echo "nothing to commit"
```

---

## Self-Review (выполнено при написании плана)

- **Покрытие спеки:** контракт API (Task 6,7), хранение (Task 1), webhook-синк (Task 9), lazy-fallback (Task 7), getReceipts (Task 2), QR (Task 4), продавец (Task 5), edge cases pending/чужой/без оплаты/payment+refund (Task 8) — все разделы спеки имеют задачу.
- **Плейсхолдеры:** отсутствуют — в каждом code-шаге полный код.
- **Согласованность типов:** `getReceipts(?string $paymentId, ?string $refundId)` одинаков в интерфейсе/YooKassa/Fake; `syncForTransaction(PaymentTransaction)` одинаков в сервисе/контроллере/PaymentService; статус-enum `PaymentTransactionStatus`, тип-enum `PaymentReceiptType` — согласованы во всех задачах.

## Замечания по раскатке

- Фича generic — после merge раскатать на форки (lastochka/electrohub/mendev/cron `storefront_back`) через sync-core по команде. `YOOKASSA_SELLER_NAME`/`YOOKASSA_SELLER_INN`/`vat_code`/СНО — задать в `.env` каждого форка.
- Деплой: после push — автодеплой; на сервере выполнить миграцию (`payment_receipts`) и `route:clear`. Верификация — реальный `GET /api/v1/orders/{id}/receipts` с токеном на dev по оплаченному заказу.
- Мобилке передать только контракт ручки: `GET /api/v1/orders/{order}/receipts`, auth Bearer, ответы `200` (массив `data`) / `403` (чужой заказ) / `404` (нет заказа).
