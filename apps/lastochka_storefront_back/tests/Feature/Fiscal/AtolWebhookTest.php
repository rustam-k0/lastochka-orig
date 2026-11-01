<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Enums\PaymentTransactionStatus;
use App\Enums\ReceiptProvider;
use App\Jobs\SendReceiptReadyNotification;
use App\Models\Order;
use App\Models\PaymentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AtolWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_atol_callback_done_writes_fiscal_and_notifies(): void
    {
        Queue::fake();
        $order = Order::factory()->create();
        $receipt = PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U1',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Pending->value,
            'amount' => 100,
            'items' => [],
            'raw' => [],
        ]);

        $this->postJson('/api/v1/webhooks/atol', [
            'uuid' => 'U1',
            'status' => 'done',
            'payload' => [
                'fn_number' => 'FN',
                'fiscal_document_number' => 'FD',
                'fiscal_document_attribute' => 'FP',
                'ofd_receipt_url' => 'http://x',
                'receipt_datetime' => '2026-07-09T10:00:00',
            ],
        ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('payment_receipts', [
            'id' => $receipt->id,
            'status' => PaymentTransactionStatus::Succeeded->value,
            'fiscal_storage_number' => 'FN',
            'fiscal_document_number' => 'FD',
            'fiscal_attribute' => 'FP',
            'receipt_url' => 'http://x',
        ]);
        $receipt->refresh();
        $this->assertNotNull($receipt->registered_at);
        $this->assertSame('2026-07-09 10:00:00', $receipt->registered_at->format('Y-m-d H:i:s'));

        Queue::assertPushed(SendReceiptReadyNotification::class);
    }

    public function test_atol_callback_fail_marks_canceled_without_notify(): void
    {
        Queue::fake();
        $order = Order::factory()->create();
        $receipt = PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U2',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Pending->value,
            'amount' => 100,
            'items' => [],
            'raw' => [],
        ]);

        $this->postJson('/api/v1/webhooks/atol', [
            'uuid' => 'U2',
            'status' => 'fail',
            'payload' => ['error' => 'some error'],
        ])->assertOk();

        $this->assertDatabaseHas('payment_receipts', [
            'id' => $receipt->id,
            'status' => PaymentTransactionStatus::Canceled->value,
        ]);
        Queue::assertNotPushed(SendReceiptReadyNotification::class);
    }

    public function test_atol_callback_already_succeeded_is_noop(): void
    {
        Queue::fake();
        $order = Order::factory()->create();
        $receipt = PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U3',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'fiscal_storage_number' => 'OLD_FN',
            'amount' => 100,
            'items' => [],
            'raw' => [],
        ]);

        $this->postJson('/api/v1/webhooks/atol', [
            'uuid' => 'U3',
            'status' => 'done',
            'payload' => ['fn_number' => 'NEW_FN'],
        ])->assertOk();

        $this->assertDatabaseHas('payment_receipts', [
            'id' => $receipt->id,
            'fiscal_storage_number' => 'OLD_FN',
        ]);
        Queue::assertNotPushed(SendReceiptReadyNotification::class);
    }

    public function test_atol_callback_unknown_uuid_is_noop(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/webhooks/atol', [
            'uuid' => 'UNKNOWN',
            'status' => 'done',
            'payload' => ['fn_number' => 'FN'],
        ])->assertOk()->assertJson(['status' => 'ok']);

        Queue::assertNotPushed(SendReceiptReadyNotification::class);
    }
}
