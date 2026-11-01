<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentReceipt;
use App\Services\Payment\Fiscal\AtolClient;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class PollAtolReceiptJob implements ShouldQueue
{
    use Queueable;

    private const MAX_ATTEMPTS = 6;

    private const DELAYS = [15, 30, 60, 120, 300, 600];

    public function __construct(
        private int $paymentReceiptId,
        private int $attempt,
    ) {}

    public function handle(AtolClient $client): void
    {
        $receipt = PaymentReceipt::find($this->paymentReceiptId);
        if ($receipt === null || $receipt->status === PaymentTransactionStatus::Succeeded) {
            return;
        }

        $report = $client->report((string) $receipt->external_id);
        $status = $report['status'] ?? 'wait';

        if ($status === 'done') {
            $p = $report['payload'] ?? [];
            $receipt->update([
                'status' => PaymentTransactionStatus::Succeeded->value,
                'fiscal_storage_number' => $p['fn_number'] ?? null,
                'fiscal_document_number' => $p['fiscal_document_number'] ?? null,
                'fiscal_attribute' => $p['fiscal_document_attribute'] ?? null,
                'receipt_url' => $p['ofd_receipt_url'] ?? null,
                'registered_at' => isset($p['receipt_datetime']) ? Carbon::parse($p['receipt_datetime']) : now(),
                'raw' => $report,
            ]);
            SendReceiptReadyNotification::dispatch($receipt->id);

            return;
        }

        if ($status === 'fail') {
            $receipt->update(['status' => PaymentTransactionStatus::Canceled->value, 'raw' => $report]);
            Log::error('ATOL receipt failed', ['receipt' => $receipt->id, 'report' => $report]);
            if (app()->bound('sentry')) {
                app('sentry')->captureMessage('ATOL fiscalization failed for receipt '.$receipt->id);
            }

            return;
        }

        // wait — повторить
        if ($this->attempt < self::MAX_ATTEMPTS) {
            self::dispatch($this->paymentReceiptId, $this->attempt + 1)
                ->delay(now()->addSeconds(self::DELAYS[$this->attempt] ?? 600));
        }
    }
}
