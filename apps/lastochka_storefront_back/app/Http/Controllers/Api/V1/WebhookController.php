<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentTransactionStatus;
use App\Enums\ReceiptProvider;
use App\Http\Controllers\Controller;
use App\Jobs\SendReceiptReadyNotification;
use App\Models\PaymentReceipt;
use App\Services\Payment\PaymentService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[Group('Webhook', weight: 20)]
final class WebhookController extends Controller
{
    /**
     * IP-адреса YooKassa для верификации webhook.
     */
    private const YOOKASSA_IPS = [
        '185.71.76.0/27',
        '185.71.77.0/27',
        '77.75.153.0/25',
        '77.75.156.11',
        '77.75.156.35',
        '77.75.154.128/25',
        '2a02:5180::/32',
    ];

    /**
     * Webhook от YooKassa
     *
     * Обрабатываются события `payment.waiting_for_capture`, `payment.succeeded`,
     * `payment.canceled`, `refund.succeeded`; остальные только логируются.
     * Ответ всегда 200. Запрос с IP вне списка ЮKassa: 403.
     *
     * @response array{status: string}
     */
    #[ScrambleResponse(403, description: 'Запрос с IP вне списка ЮKassa', type: 'array{message: string}')]
    public function yookassa(Request $request, PaymentService $paymentService): JsonResponse
    {
        if (!$this->isYooKassaIp($request->ip())) {
            Log::warning('YooKassa webhook from unknown IP', ['ip' => $request->ip()]);
            abort(403);
        }

        $event = $request->input('event');
        $object = $request->input('object', []);
        $paymentId = $object['id'] ?? null;
        $cancellationReason = $object['cancellation_details']['reason'] ?? null;

        Log::info('YooKassa webhook received', [
            'event' => $event,
            'payment_id' => $paymentId,
            'cancellation_reason' => $cancellationReason,
        ]);

        match ($event) {
            'payment.waiting_for_capture' => $paymentService->handleHoldSucceeded($paymentId),
            'payment.succeeded' => $paymentService->handlePaymentSucceeded($paymentId),
            'payment.canceled' => $paymentService->handleHoldCanceled($paymentId, $cancellationReason),
            'refund.succeeded' => $paymentService->handleRefundSucceeded(
                refundId: $paymentId,
                paymentId: $object['payment_id'] ?? '',
                amount: (float) ($object['amount']['value'] ?? 0),
            ),
            default => Log::info('YooKassa webhook: unhandled event', ['event' => $event]),
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * Webhook от АТОЛ (async-результат фискализации)
     *
     * `status: done` переводит чек в succeeded и заполняет фискальные реквизиты,
     * `status: fail` переводит чек в canceled. Ответ всегда 200, в том числе для
     * неизвестного `uuid` и уже пробитого чека.
     *
     * @response array{status: string}
     */
    public function atol(Request $request): JsonResponse
    {
        $uuid = $request->input('uuid');
        $status = $request->input('status');
        $payload = $request->input('payload', []);

        Log::info('ATOL callback', ['uuid' => $uuid, 'status' => $status]);

        $receipt = PaymentReceipt::where('provider', ReceiptProvider::Atol->value)
            ->where('external_id', $uuid)
            ->first();

        if ($receipt === null || $receipt->status === PaymentTransactionStatus::Succeeded) {
            return response()->json(['status' => 'ok']);
        }

        if ($status === 'done') {
            $receipt->update([
                'status' => PaymentTransactionStatus::Succeeded->value,
                'fiscal_storage_number' => $payload['fn_number'] ?? null,
                'fiscal_document_number' => $payload['fiscal_document_number'] ?? null,
                'fiscal_attribute' => $payload['fiscal_document_attribute'] ?? null,
                'receipt_url' => $payload['ofd_receipt_url'] ?? null,
                'registered_at' => isset($payload['receipt_datetime'])
                    ? Carbon::parse($payload['receipt_datetime']) : now(),
                'raw' => $request->all(),
            ]);
            SendReceiptReadyNotification::dispatch($receipt->id);
        } elseif ($status === 'fail') {
            $receipt->update([
                'status' => PaymentTransactionStatus::Canceled->value,
                'raw' => $request->all(),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    private function isYooKassaIp(string $ip): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }

        foreach (self::YOOKASSA_IPS as $range) {
            if (str_contains($range, '/')) {
                if ($this->ipInCidr($ip, $range)) {
                    return true;
                }
            } elseif ($ip === $range) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (str_contains($cidr, ':') && !str_contains($ip, ':')) {
            return false;
        }

        [$subnet, $mask] = explode('/', $cidr);

        if (str_contains($ip, ':')) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
