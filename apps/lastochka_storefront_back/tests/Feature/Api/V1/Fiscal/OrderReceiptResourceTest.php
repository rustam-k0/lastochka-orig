<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Fiscal;

use App\Enums\PaymentTransactionStatus;
use App\Enums\ReceiptProvider;
use App\Jobs\FiscalizeRefundJob;
use App\Jobs\FiscalizeSaleJob;
use App\Jobs\SendReceiptReadyNotification;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class OrderReceiptResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_endpoint_exposes_receipt_url_and_seller(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => ['atol' => ['seller_name' => 'ООО Ромашка', 'inn' => '7700000000']],
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U-succeeded',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'fiscal_storage_number' => 'FN',
            'fiscal_document_number' => 'FD',
            'fiscal_attribute' => 'FP',
            'receipt_url' => 'http://ofd/x',
            'registered_at' => now(),
            'amount' => 600.00,
            'items' => [
                ['description' => 'Молоко 3.2% 1л', 'quantity' => '1.00', 'amount' => '600.00', 'vat_code' => 4],
            ],
            'raw' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.receiptUrl', 'http://ofd/x')
            ->assertJsonPath('data.0.seller.name', 'ООО Ромашка')
            ->assertJsonPath('data.0.seller.inn', '7700000000');
    }

    /**
     * ATOL-11651 fix: чек АТОЛ хранится в PaymentReceipt.items в том же unified
     * виде, что и ЮKassa (description/amount/quantity/vat_code). Резолвер обязан
     * посчитать НЕНУЛЕВОЙ vat_amount для vat_code=4 (20%, "в том числе"):
     * 600.00 × 20/120 = 100.00.
     */
    public function test_atol_receipt_items_render_correct_non_zero_vat_amount(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => ['atol' => ['seller_name' => 'ООО Ромашка', 'inn' => '7700000000']],
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U-vat20',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'fiscal_storage_number' => 'FN',
            'fiscal_document_number' => 'FD',
            'fiscal_attribute' => 'FP',
            'registered_at' => now(),
            'amount' => 600.00,
            // Unified-shape ATOL-позиция (как её теперь сохраняет AtolFiscalizer::unifyItems).
            'items' => [
                ['description' => 'Молоко 3.2% 1л', 'quantity' => '1.00', 'amount' => '600.00', 'vat_code' => 4],
            ],
            'raw' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.items.0.vat_code', 4)
            ->assertJsonPath('data.0.items.0.vat_rate', '20')
            ->assertJsonPath('data.0.items.0.vat_amount', '100.00')
            ->assertJsonPath('data.0.vat.0.vat_code', 4)
            ->assertJsonPath('data.0.vat.0.amount', '100.00');
    }

    /**
     * ATOL flat-credentials bug: RabbitMQ propagates `credentials` flat (no `atol`
     * wrapper key — see AtolReceiptBuilderTest for the full explanation). `sellerInfo()`
     * must still resolve `seller_name`/`inn` from the flat dict, not silently fall
     * back to the (unrelated) YooKassa seller config.
     */
    public function test_seller_reads_flat_atol_credentials_from_rabbitmq_propagation(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => ['seller_name' => 'ООО Ромашка', 'inn' => '7700000000'],
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U-flat',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'fiscal_storage_number' => 'FN',
            'fiscal_document_number' => 'FD',
            'fiscal_attribute' => 'FP',
            'receipt_url' => 'http://ofd/flat',
            'registered_at' => now(),
            'amount' => 600.00,
            'items' => [
                ['description' => 'Молоко 3.2% 1л', 'quantity' => '1.00', 'amount' => '600.00', 'vat_code' => 4],
            ],
            'raw' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.seller.name', 'ООО Ромашка')
            ->assertJsonPath('data.0.seller.inn', '7700000000');
    }

    public function test_seller_falls_back_to_yookassa_config_when_driver_is_not_atol(): void
    {
        config([
            'services.yookassa.seller.name' => 'Тестовый Продавец',
            'services.yookassa.seller.inn' => '9909999999',
        ]);

        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'yookassa',
            'is_active' => true,
            'credentials' => [],
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Yookassa->value,
            'external_id' => 'rt-1',
            'yookassa_receipt_id' => 'rt-1',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'fiscal_storage_number' => 'FN',
            'fiscal_document_number' => 'FD',
            'fiscal_attribute' => 'FP',
            'receipt_url' => null,
            'registered_at' => now(),
            'amount' => 600.00,
            'items' => [
                ['description' => 'Молоко 3.2% 1л', 'quantity' => '1.00', 'amount' => '600.00', 'vat_code' => 4],
            ],
            'raw' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.receiptUrl', null)
            ->assertJsonPath('data.0.seller.name', 'Тестовый Продавец')
            ->assertJsonPath('data.0.seller.inn', '9909999999');
    }

    public function test_pending_atol_receipt_is_lazily_polled_and_updated_before_response(): void
    {
        // Базовый TestCase фейкает PollAtolReceiptJob (чтобы обычные тесты не били
        // реальный HTTP). Этот тест целенаправленно проверяет lazy-poll — исключаем
        // job из фейка, чтобы dispatchSync реально исполнился (QUEUE_CONNECTION=sync).
        Queue::fake([FiscalizeSaleJob::class, FiscalizeRefundJob::class, SendReceiptReadyNotification::class]);

        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => ['atol' => [
                'mode' => 'test',
                'login' => 'u',
                'password' => 'p',
                'group_code' => 'grp',
                'seller_name' => 'ООО Ромашка',
                'inn' => '7700000000',
            ]],
        ]);

        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN'], 200),
            '*/grp/report/U-pending*' => Http::response([
                'uuid' => 'U-pending',
                'status' => 'done',
                'payload' => [
                    'fn_number' => 'FN1',
                    'fiscal_document_number' => 'FD1',
                    'fiscal_document_attribute' => 'FP1',
                    'ofd_receipt_url' => 'http://ofd/y',
                    'receipt_datetime' => '2026-07-09T10:00:00',
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        $receipt = PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U-pending',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Pending->value,
            'amount' => 500.00,
            'items' => [],
            'raw' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'succeeded')
            ->assertJsonPath('data.0.receiptUrl', 'http://ofd/y');

        $this->assertSame(PaymentTransactionStatus::Succeeded, $receipt->fresh()->status);
        $this->assertSame('http://ofd/y', $receipt->fresh()->receipt_url);
    }

    /**
     * Finding #5: `ensureReceiptsFresh()` раньше вызывал
     * `PollAtolReceiptJob::dispatchSync()` без защиты — ConnectionException
     * (ATOL недоступен/таймаут) бросался прямо из HTTP-хендлера контроллера
     * и клиентский `GET /orders/{order}/receipts` отдавал 500. Фикс оборачивает
     * ТОЛЬКО ATOL-поллинг в try/catch: сбой логируется, эндпоинт всё равно
     * отвечает 200 и отдаёт чек в состоянии pending (как он и был до опроса).
     */
    public function test_pending_atol_receipt_poll_failure_does_not_500_the_endpoint(): void
    {
        Queue::fake([FiscalizeSaleJob::class, FiscalizeRefundJob::class, SendReceiptReadyNotification::class]);

        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => ['atol' => [
                'mode' => 'test',
                'login' => 'u',
                'password' => 'p',
                'group_code' => 'grp',
                'seller_name' => 'ООО Ромашка',
                'inn' => '7700000000',
            ]],
        ]);

        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN'], 200),
            '*/grp/report/U-timeout*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            },
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        $receipt = PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U-timeout',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Pending->value,
            'amount' => 500.00,
            'items' => [],
            'raw' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'pending');

        $this->assertSame(PaymentTransactionStatus::Pending, $receipt->fresh()->status);
    }

    /**
     * Весовая позиция АТОЛ-чека: `quantity` в кг с точностью до грамма и `unit` («кг»/«л»)
     * пробрасываются в ответ как есть, НДС считается от amount × quantity (600 × 0.63 = 378).
     * Старые чеки без `unit` в JSON — поле отсутствует, ресурс не падает.
     */
    public function test_receipt_endpoint_exposes_weighted_item_unit_and_gram_precision(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U-weighted',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'fiscal_storage_number' => 'FN',
            'fiscal_document_number' => 'FD',
            'fiscal_attribute' => 'FP',
            'registered_at' => now(),
            'amount' => 546.17,
            'items' => [
                ['description' => 'Голубика Вес', 'quantity' => '0.63', 'amount' => '600.00', 'vat_code' => 3, 'unit' => 'кг'],
                ['description' => 'Нектарин Отборный', 'quantity' => '1.078', 'amount' => '156.00', 'vat_code' => 3, 'unit' => 'кг'],
                ['description' => 'Чай (старый формат)', 'quantity' => '1.00', 'amount' => '74.90', 'vat_code' => 4],
            ],
            'raw' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.items.0.quantity', '0.63')
            ->assertJsonPath('data.0.items.0.unit', 'кг')
            ->assertJsonPath('data.0.items.0.vat_amount', number_format(378 * 10 / 110, 2, '.', ''))
            ->assertJsonPath('data.0.items.1.quantity', '1.078')
            ->assertJsonPath('data.0.items.1.unit', 'кг')
            ->assertJsonMissingPath('data.0.items.2.unit');
    }

    /**
     * Стоимость строки чека («2 × 81 ₽ = 162 ₽») клиент получает готовой в `sum`,
     * а не считает произведением: цена весовой позиции АТОЛ производна от суммы
     * (`round(sum / quantity, 2)`) и обратное умножение расходится с фискальным
     * документом на копейку — 56.39 × 2.51 = 141.5389 при sum 141.53. НДС считается
     * от той же суммы строки, что показана клиенту.
     */
    public function test_receipt_item_exposes_stored_line_sum_instead_of_price_times_quantity(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U-linesum',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'fiscal_storage_number' => 'FN',
            'fiscal_document_number' => 'FD',
            'fiscal_attribute' => 'FP',
            'registered_at' => now(),
            'amount' => 303.53,
            'items' => [
                ['description' => 'Тыква', 'quantity' => '2.51', 'amount' => '56.39', 'sum' => '141.53', 'vat_code' => 3, 'unit' => 'кг'],
                ['description' => 'Макароны Гео-простор', 'quantity' => '2.00', 'amount' => '81.00', 'sum' => '162.00', 'vat_code' => 3, 'unit' => null],
            ],
            'raw' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.items.0.sum', '141.53')
            ->assertJsonPath('data.0.items.0.amount', '56.39')
            ->assertJsonPath('data.0.items.0.vat_amount', number_format(141.53 * 10 / 110, 2, '.', ''))
            ->assertJsonPath('data.0.items.1.sum', '162.00')
            ->assertJsonPath('data.0.items.1.amount', '81.00')
            ->assertJsonPath('data.0.items.1.quantity', '2.00');
    }

    /**
     * Чеки, пробитые до появления `sum` в хранимой форме, всё равно отдают стоимость
     * строки: ресурс восстанавливает её произведением. Поле в ответе есть всегда —
     * клиенту не нужна ветка «посчитать самому», иначе старый чек показал бы пустую сумму.
     */
    public function test_receipt_item_sum_falls_back_to_price_times_quantity_for_legacy_receipts(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U-legacy-sum',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'fiscal_storage_number' => 'FN',
            'fiscal_document_number' => 'FD',
            'fiscal_attribute' => 'FP',
            'registered_at' => now(),
            'amount' => 391.20,
            'items' => [
                ['description' => 'Лапша Кэмми Яичная 250г', 'quantity' => '4.00', 'amount' => '97.80', 'vat_code' => 3],
            ],
            'raw' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.items.0.sum', '391.20')
            ->assertJsonPath('data.0.items.0.vat_amount', number_format(391.20 * 10 / 110, 2, '.', ''));
    }
}
