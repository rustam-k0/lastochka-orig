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
