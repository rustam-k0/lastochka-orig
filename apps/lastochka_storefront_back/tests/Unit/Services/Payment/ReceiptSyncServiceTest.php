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
        // Стоимость строки в unified-форме: у ЮKassa её нет в исходных данных,
        // считаем произведением цены на количество (600.00 × 1).
        $this->assertSame('600.00', $receipt->items[0]['sum']);
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
