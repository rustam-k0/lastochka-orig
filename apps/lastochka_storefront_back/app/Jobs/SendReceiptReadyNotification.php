<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\PaymentReceipt;
use App\Models\UserNotification;
use App\Services\EmailNotificationService;
use App\Services\FirebaseNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Пуш «Чек готов» — шлётся один раз при успешной фискализации чека
 * (сборка событий: YooKassa/АТОЛ webhook callback + poll-джоба могут
 * оба триггернуть dispatch — идемпотентность через notified_at).
 */
final class SendReceiptReadyNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private int $paymentReceiptId,
    ) {}

    public function handle(
        FirebaseNotificationService $firebaseNotificationService,
        EmailNotificationService $emailNotificationService,
    ): void {
        $receipt = PaymentReceipt::with('order.user')->find($this->paymentReceiptId);

        if (! $receipt || ! $receipt->order || ! $receipt->order->user) {
            Log::warning('SendReceiptReadyNotification: receipt/order/user not found', [
                'payment_receipt_id' => $this->paymentReceiptId,
            ]);

            return;
        }

        // Идемпотентность: webhook-callback и poll-джоба могут оба
        // задиспатчить эту джобу для одного и того же чека.
        if ($receipt->notified_at !== null) {
            return;
        }

        $order = $receipt->order;
        $user = $order->user;

        $title = 'Чек готов';
        $body = "Фискальный чек по заказу №{$order->id} готов";

        $notification = UserNotification::create([
            'user_id' => $user->id,
            'type' => NotificationType::Order,
            'title' => $title,
            'short_description' => $body,
            'body' => $body,
            'deep_link' => "order/{$order->id}",
        ]);

        $dataPayload = [
            'type' => 'receipt_ready',
            'order_id' => (string) $order->id,
            'action' => 'open_order_details',
            'notification_id' => (string) $notification->id,
        ];

        if ($user->canReceivePush(NotificationType::Order)) {
            try {
                $firebaseNotificationService->sendToUser(
                    $user,
                    ['title' => $title, 'body' => $body],
                    $dataPayload
                );
            } catch (\Throwable $e) {
                Log::warning('receipt-ready push failed', [
                    'receipt' => $receipt->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($user->canReceiveEmail(NotificationType::Order)) {
            try {
                $emailNotificationService->sendToUser(
                    $user,
                    ['title' => $title, 'body' => $body],
                    $dataPayload
                );
            } catch (\Throwable $e) {
                Log::warning('receipt-ready email failed', [
                    'receipt' => $receipt->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $receipt->update(['notified_at' => now()]);

        Log::info('Receipt ready notification sent', [
            'payment_receipt_id' => $receipt->id,
            'order_id' => $order->id,
            'user_id' => $user->id,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendReceiptReadyNotification failed', [
            'payment_receipt_id' => $this->paymentReceiptId,
            'error' => $e->getMessage(),
        ]);
        if (app()->bound('sentry')) {
            app('sentry')->captureException($e);
        }
    }
}
