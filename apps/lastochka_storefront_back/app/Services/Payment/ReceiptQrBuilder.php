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
