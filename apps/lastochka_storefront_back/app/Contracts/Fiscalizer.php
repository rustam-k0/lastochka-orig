<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Payment\FiscalReceiptResult;
use App\Enums\PaymentType;
use App\Models\Order;

interface Fiscalizer
{
    public function fiscalizeSale(Order $order, PaymentType $paymentType, float $amount): FiscalReceiptResult;

    public function fiscalizeRefund(Order $order, float $amount, ?string $refundId = null): FiscalReceiptResult;
}
