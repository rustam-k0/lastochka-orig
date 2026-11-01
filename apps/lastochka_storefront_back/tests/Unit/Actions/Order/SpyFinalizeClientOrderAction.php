<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Order;

use App\Actions\Order\FinalizeClientOrderAction;

/**
 * Test spy: перехватывает вызовы PaymentService consolidated-методов без их реального выполнения.
 *
 * Необходимо потому что PaymentService::captureClientOrder / cancelConsolidatedHold / refundClientOrder
 * ещё НЕТ в main ветке (они появятся при merge P2.1). FinalizeClientOrderAction вызывает их через
 * protected hooks, которые spy переопределяет.
 *
 * @phpstan-ignore-next-line
 */
final class SpyFinalizeClientOrderAction extends FinalizeClientOrderAction
{
    /** @var array<int, array{method: string, clientOrderId: string, amount?: float}> */
    public array $calls = [];

    protected function doCancelConsolidatedHold(string $clientOrderId): void
    {
        $this->calls[] = ['method' => 'cancelConsolidatedHold', 'clientOrderId' => $clientOrderId];
    }

    protected function doCaptureClientOrder(string $clientOrderId, float $amount): void
    {
        $this->calls[] = ['method' => 'captureClientOrder', 'clientOrderId' => $clientOrderId, 'amount' => $amount];
    }

    protected function doRefundClientOrder(string $clientOrderId, float $amount): void
    {
        $this->calls[] = ['method' => 'refundClientOrder', 'clientOrderId' => $clientOrderId, 'amount' => $amount];
    }
}
