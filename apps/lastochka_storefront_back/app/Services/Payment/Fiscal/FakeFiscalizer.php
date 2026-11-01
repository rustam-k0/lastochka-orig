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

    public function fiscalizeRefund(Order $order, float $amount, ?string $refundId = null): FiscalReceiptResult
    {
        $this->refunds[] = ['order' => $order->id, 'amount' => $amount, 'refundId' => $refundId];

        return new FiscalReceiptResult(ReceiptProvider::Atol, 'fake-refund-uuid', 'pending');
    }
}
