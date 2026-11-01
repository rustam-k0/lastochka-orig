<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\UserNotification;
use App\Services\EmailNotificationService;
use App\Services\FirebaseNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SendOrderStatusNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Срок разблокировки остатка холда банком клиента, который называем
     * в уведомлении. Реальный срок зависит от банка-эмитента.
     */
    private const HOLD_RELEASE_WORKING_DAYS = 3;

    public function __construct(
        private int $orderId,
        private OrderStatus $newStatus,
    ) {}

    public function handle(
        FirebaseNotificationService $firebaseNotificationService,
        EmailNotificationService $emailNotificationService,
    ): void {
        $order = Order::with('user')->find($this->orderId);

        if (! $order || ! $order->user) {
            Log::warning('SendOrderStatusNotification: order or user not found', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        $notificationText = $this->getNotificationText($this->newStatus, $order);

        if (! $notificationText) {
            return;
        }

        // Гейт типа: если пользователь выключил уведомления о заказах,
        // не пишем in-app и не шлём push.
        if (! $order->user->isNotificationTypeEnabled(NotificationType::Order)) {
            Log::info('Order notification skipped by user preference', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'status' => $this->newStatus->value,
            ]);

            return;
        }

        // Сохраняем уведомление в БД
        $notification = UserNotification::create([
            'user_id' => $order->user_id,
            'type' => NotificationType::Order,
            'title' => $notificationText['title'],
            'short_description' => $notificationText['body'],
            'body' => $notificationText['body'],
            'deep_link' => "order/{$order->id}",
        ]);

        $dataPayload = [
            'type' => 'order_status_changed',
            'order_id' => (string) $order->id,
            'status' => $this->newStatus->value,
            'action' => 'open_order_details',
            'notification_id' => (string) $notification->id,
        ];

        // Push шлём только если общий push-канал включён.
        if ($order->user->canReceivePush(NotificationType::Order)) {
            $firebaseNotificationService->sendToUser(
                $order->user,
                $notificationText,
                $dataPayload
            );
        }

        // Email — независимый канал со своим тогглом и требованием адреса.
        if ($order->user->canReceiveEmail(NotificationType::Order)) {
            try {
                $emailNotificationService->sendToUser(
                    $order->user,
                    $notificationText,
                    $dataPayload
                );
            } catch (\Throwable $e) {
                Log::warning('order-status email failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Order status notification sent', [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'status' => $this->newStatus->value,
        ]);
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function getNotificationText(OrderStatus $status, Order $order): ?array
    {
        $orderNumber = "№{$order->id}";
        $isPickup = $order->shipping_method === \App\Enums\ShippingMethod::Pickup;

        if ($status === OrderStatus::Assembled) {
            $amountChangeText = $this->getAmountChangeText($order);

            if ($amountChangeText !== null) {
                return [
                    'title' => 'Сумма заказа изменилась',
                    'body' => "Заказ {$orderNumber} собран. {$amountChangeText}"
                        . ($isPickup ? $this->getPickupWaitText($order) : ''),
                ];
            }
        }

        if ($isPickup) {
            return $this->getPickupNotificationText($status, $orderNumber, $order);
        }

        return match ($status) {
            OrderStatus::Assembling => [
                'title' => 'Заказ в сборке',
                'body' => "Ваш заказ {$orderNumber} передан в сборку. Мы уже начали собирать ваши товары!",
            ],
            OrderStatus::Assembled => [
                'title' => 'Заказ собран',
                'body' => "Ваш заказ {$orderNumber} собран и готов к доставке. Скоро курьер заберет его из магазина.",
            ],
            OrderStatus::InDelivery => $order->payment_type === PaymentType::Cash
                ? [
                    'title' => 'Курьер выехал к вам',
                    'body' => "Курьер выехал к вам. Сумма к оплате наличными: {$order->total} ₽",
                ]
                : [
                    'title' => 'Заказ в доставке',
                    'body' => "Ваш заказ {$orderNumber} находится в пути. Курьер скоро свяжется с вами для уточнения времени доставки.",
                ],
            OrderStatus::Delivered => [
                'title' => 'Заказ доставлен',
                'body' => "Ваш заказ {$orderNumber} успешно доставлен. Спасибо за покупку!",
            ],
            OrderStatus::Cancelled => [
                'title' => 'Заказ отменён',
                'body' => "Ваш заказ {$orderNumber} был отменён. Если у вас есть вопросы, свяжитесь с поддержкой.",
            ],
            default => null,
        };
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function getPickupNotificationText(OrderStatus $status, string $orderNumber, Order $order): ?array
    {
        return match ($status) {
            OrderStatus::Assembling => [
                'title' => 'Заказ в сборке',
                'body' => "Ваш заказ {$orderNumber} собирается.",
            ],
            OrderStatus::Assembled => [
                'title' => 'Заказ собран',
                'body' => "Пожалуйста, заберите заказ {$orderNumber}." . $this->getPickupWaitText($order),
            ],
            OrderStatus::Delivered => [
                'title' => 'Заказ выдан',
                'body' => "Ваш заказ {$orderNumber} выдан. Спасибо за покупку!",
            ],
            OrderStatus::Cancelled => [
                'title' => 'Заказ отменён',
                'body' => "Ваш заказ {$orderNumber} был отменён. Денежные средства будут возвращены.",
            ],
            default => null,
        };
    }

    /**
     * Текст про изменение суммы после сборки для оплаты картой.
     *
     * Сравниваем заблокированное на карте (held_amount) со списанным по итогам
     * сборки (captured_amount = capture холда + доплаты). Меньше — остаток холда
     * разблокирует банк клиента, больше — разницу списали отдельным платежом.
     * Без подтверждённого холда или списания (наличные, потерянный вебхук,
     * провал списания) сравнивать нечего — уходит обычный текст «Заказ собран».
     */
    private function getAmountChangeText(Order $order): ?string
    {
        if ($order->payment_type !== PaymentType::Card) {
            return null;
        }

        $held = round((float) ($order->held_amount ?? 0), 2);
        $captured = round((float) ($order->captured_amount ?? 0), 2);

        if ($held <= 0.01 || $captured <= 0.01) {
            return null;
        }

        $difference = round($captured - $held, 2);

        if (abs($difference) < 0.01) {
            return null;
        }

        $summary = 'Итого ' . $this->formatRubles($captured) . ' вместо ' . $this->formatRubles($held) . '.';

        if ($difference < 0) {
            return $summary . ' Разница ' . $this->formatRubles(-$difference)
                . ' вернётся на карту в течение ' . self::HOLD_RELEASE_WORKING_DAYS . ' рабочих дней.';
        }

        return $summary . ' С карты дополнительно списано ' . $this->formatRubles($difference) . '.';
    }

    /**
     * 1000 → «1 000 ₽», 542.8 → «542,80 ₽»: копейки только если они есть.
     * Разделитель разрядов — неразрывный пробел, чтобы сумма не переносилась.
     */
    private function formatRubles(float $amount): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;

        return number_format($amount, $decimals, ',', "\u{00A0}") . "\u{00A0}₽";
    }

    private function getPickupWaitText(Order $order): string
    {
        $store = $order->store;
        if (! $store) {
            $store = \App\Models\Store::find($order->store_id);
        }
        if (! $store || ! $store->pickup_auto_cancel_enabled) {
            return '';
        }

        $hasPerishable = $order->items()
            ->whereHas('product.categories', fn ($q) => $q->where('is_perishable', true))
            ->exists();

        // Согласовано с OrderEventTransformer::handlePickupAssembled: дедлайн
        // авто-отмены = waitHours + graceHours. Мобилка показывает обратный
        // таймер от pickup_timer_expires_at — пуш должен называть то же окно.
        $waitHours = $hasPerishable
            ? ($store->pickup_wait_perishable_hours ?? 1)
            : ($store->pickup_wait_regular_hours ?? 3);
        $graceHours = $store->pickup_grace_period_hours ?? 1;
        $totalHours = $waitHours + $graceHours;

        return " Заберите в течение {$totalHours} ч.";
    }
}
