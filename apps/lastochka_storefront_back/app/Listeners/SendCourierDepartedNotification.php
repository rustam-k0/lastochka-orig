<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChangedEvent;
use App\Services\Notification\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

final class SendCourierDepartedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly PushNotificationService $pushNotificationService
    ) {
    }

    /**
     * Обработка события смены статуса заказа.
     */
    public function handle(OrderStatusChangedEvent $event): void
    {
        // Отправляем уведомление только при переходе в статус "В доставке"
        if ($event->newStatus !== OrderStatus::InDelivery) {
            return;
        }

        try {
            $this->pushNotificationService->sendCourierDepartedNotification($event->order);
        } catch (\Throwable $e) {
            Log::error('Failed to send courier departed notification', [
                'order_id' => $event->order->id,
                'user_id' => $event->order->user_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Не пробрасываем исключение - уведомления не должны блокировать основной процесс
        }
    }
}