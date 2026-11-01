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
