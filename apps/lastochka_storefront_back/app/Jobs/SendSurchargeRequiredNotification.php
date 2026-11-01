<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\Order;
use App\Models\UserNotification;
use App\Services\EmailNotificationService;
use App\Services\FirebaseNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SendSurchargeRequiredNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private int $orderId,
    ) {}

    public function handle(
        FirebaseNotificationService $firebaseNotificationService,
        EmailNotificationService $emailNotificationService,
    ): void {
        $order = Order::with('user')->find($this->orderId);

        if (! $order || ! $order->user) {
            Log::warning('SendSurchargeRequiredNotification: order or user not found', ['order_id' => $this->orderId]);

            return;
        }

        if (! $order->user->isNotificationTypeEnabled(NotificationType::Order)) {
            return;
        }

        $amount = number_format((float) $order->surcharge_amount, 0, '.', ' ');
        $title = 'Требуется доплата по заказу';
        $body = "По заказу №{$order->id} требуется доплата {$amount} ₽. Пополните карту, с которой оплачивали заказ, — сборщик повторит списание.";

        $notification = UserNotification::create([
            'user_id' => $order->user_id,
            'type' => NotificationType::Order,
            'title' => $title,
            'short_description' => $body,
            'body' => $body,
            'deep_link' => "order/{$order->id}",
        ]);

        $dataPayload = [
            'type' => 'order_surcharge_required',
            'order_id' => (string) $order->id,
            'action' => 'open_order_details',
            'notification_id' => (string) $notification->id,
        ];

        if ($order->user->canReceivePush(NotificationType::Order)) {
            $firebaseNotificationService->sendToUser(
                $order->user,
                ['title' => $title, 'body' => $body],
                $dataPayload,
            );
        }

        if ($order->user->canReceiveEmail(NotificationType::Order)) {
            try {
                $emailNotificationService->sendToUser(
                    $order->user,
                    ['title' => $title, 'body' => $body],
                    $dataPayload,
                );
            } catch (\Throwable $e) {
                Log::warning('surcharge-required email failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
