<?php

namespace App\Services;

use App\Models\UserFcmToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmNotificationService
{
    public function __construct(
        private ?Messaging $messaging
    ) {}

    /**
     * Отправить push одному пользователю на все его устройства.
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = UserFcmToken::where('user_id', $userId)->pluck('token')->toArray();

        if (empty($tokens)) {
            Log::info('FCM: нет токенов для пользователя', ['user_id' => $userId]);
            return;
        }

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Отправить push нескольким пользователям.
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        if (empty($userIds)) {
            return;
        }

        $tokens = UserFcmToken::whereIn('user_id', $userIds)->pluck('token')->toArray();

        if (empty($tokens)) {
            Log::info('FCM: нет токенов для пользователей', ['user_ids' => $userIds]);
            return;
        }

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Отправить multicast на список токенов.
     */
    private function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        // Текст пуша в логе — иначе ошибку в самом тексте (не в доставке)
        // нечем диагностировать: отчёт FCM знает только про токены.
        Log::info('FCM: отправка', [
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'tokens' => count($tokens),
        ]);

        if (!$this->messaging) {
            Log::warning('FCM: Firebase не настроен — push не отправлен');
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body));

        if (!empty($data)) {
            $message = $message->withData($data);
        }

        try {
            $report = $this->messaging->sendMulticast($message, $tokens);

            Log::info('FCM: отправка завершена', [
                'success' => $report->successes()->count(),
                'failures' => $report->failures()->count(),
            ]);

            $this->removeInvalidTokens($report);
        } catch (\Throwable $e) {
            Log::warning('FCM: ошибка отправки', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Удалить невалидные токены из БД.
     */
    private function removeInvalidTokens($report): void
    {
        $invalidTokens = array_merge(
            $report->invalidTokens(),
            $report->unknownTokens()
        );

        if (!empty($invalidTokens)) {
            UserFcmToken::whereIn('token', $invalidTokens)->delete();

            Log::info('FCM: удалены невалидные токены', [
                'count' => count($invalidTokens),
            ]);
        }
    }
}
