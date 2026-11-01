<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\UserFcmToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

final class PushNotificationService
{
    public function __construct(
        private readonly Factory $firebaseFactory
    ) {
    }

    /**
     * Отправить уведомление о выезде курьера.
     */
    public function sendCourierDepartedNotification(Order $order): void
    {
        $tokens = $this->getUserActiveTokens($order->user_id);

        if (empty($tokens)) {
            Log::info('Push notification skipped - no FCM tokens', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
            ]);
            return;
        }

        $message = $this->buildCourierDepartedMessage($order);

        foreach ($tokens as $token) {
            try {
                $this->sendToToken($token, $message);

                Log::info('Push notification sent successfully', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'token_id' => $token->id,
                    'message' => $message,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send push notification', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'token_id' => $token->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Формирование сообщения о выезде курьера.
     */
    private function buildCourierDepartedMessage(Order $order): string
    {
        if ($order->payment_type?->value === 'cash') {
            return "Курьер выехал к вам. Сумма к оплате наличными: {$order->total} ₽";
        }

        return "Ваш заказ №{$order->id} находится в пути. Курьер скоро свяжется с вами для уточнения времени!";
    }

    /**
     * Получить активные FCM токены пользователя.
     *
     * @return \Illuminate\Database\Eloquent\Collection<UserFcmToken>
     */
    private function getUserActiveTokens(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return UserFcmToken::getActiveTokensForUser($userId);
    }

    /**
     * Отправить уведомление на конкретный токен.
     */
    private function sendToToken(UserFcmToken $fcmToken, string $message): void
    {
        try {
            $messaging = $this->firebaseFactory->createMessaging();

            $notification = Notification::create('Наш Аладдин', $message);

            $cloudMessage = CloudMessage::withTarget('token', $fcmToken->token)
                ->withNotification($notification);

            // Обновляем время последней отправки
            $fcmToken->update(['last_sent_at' => now()]);

            $messaging->send($cloudMessage);
        } catch (\Kreait\Firebase\Exception\InvalidArgumentException $e) {
            // Токен невалидный - удаляем его
            Log::warning('Invalid FCM token, removing', [
                'token_id' => $fcmToken->id,
                'token' => $fcmToken->token,
                'error' => $e->getMessage(),
            ]);

            $fcmToken->delete();
            throw $e;
        }
    }
}