<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserFcmToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseNotificationService
{
    protected Messaging $messaging;

    public function __construct(Messaging $messaging)
    {
        $this->messaging = $messaging;
    }

    /**
     * Отправить уведомления всем пользователям.
     *
     * @param  array<string, string>  $notification
     * @param  array<string, mixed>  $data
     */
    public function sendToAllUsers(array $notification, array $data = []): array
    {
        Log::info('Sending push notification to all users', [
            'title' => $notification['title'] ?? '',
            'body' => $notification['body'] ?? '',
            'data' => $data,
        ]);

        $tokens = UserFcmToken::query()
            ->whereNotNull('token')
            ->whereNotNull('user_id')
            ->whereHas('user', fn ($q) => $q->where('push_enabled', true))
            ->pluck('token')
            ->toArray();

        return $this->sendToTokens($tokens, $notification, $data);
    }

    /**
     * Отправить уведомления активным пользователям (последний вход < 30 дней).
     *
     * @param  array<string, string>  $notification
     * @param  array<string, mixed>  $data
     */
    public function sendToActiveUsers(array $notification, array $data = []): array
    {
        Log::info('Sending push notification to active users', [
            'title' => $notification['title'] ?? '',
            'body' => $notification['body'] ?? '',
            'data' => $data,
        ]);

        $tokens = UserFcmToken::query()
            ->whereNotNull('token')
            ->whereHas('user', function ($q) {
                $q->where('push_enabled', true)
                    ->where('last_login_at', '>=', now()->subDays(30));
            })
            ->pluck('token')
            ->toArray();

        return $this->sendToTokens($tokens, $notification, $data);
    }

    /**
     * Отправить уведомления по сегментам пользователей.
     *
     * @param  array<string, mixed>  $segmentCriteria
     * @param  array<string, string>  $notification
     * @param  array<string, mixed>  $data
     */
    public function sendToSegment(array $segmentCriteria, array $notification, array $data = []): array
    {
        Log::info('Sending push notification to user segment', [
            'criteria' => $segmentCriteria,
            'title' => $notification['title'] ?? '',
            'body' => $notification['body'] ?? '',
            'data' => $data,
        ]);

        // Получаем токены пользователей, соответствующих критериям сегментации
        $tokens = $this->getTokensBySegment($segmentCriteria);

        return $this->sendToTokens($tokens, $notification, $data);
    }

    /**
     * Отправить уведомление конкретному пользователю.
     *
     * @param  array<string, string>  $notification
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User $user, array $notification, array $data = []): array
    {
        Log::info('Sending push notification to specific user', [
            'user_id' => $user->id,
            'title' => $notification['title'] ?? '',
            'body' => $notification['body'] ?? '',
            'data' => $data,
        ]);

        if (! ($user->push_enabled ?? true)) {
            Log::info('Push notification skipped: user disabled push channel', [
                'user_id' => $user->id,
            ]);

            return ['success' => 0, 'failure' => 0, 'total' => 0, 'skipped' => 'push_disabled'];
        }

        $tokens = UserFcmToken::getActiveTokensForUser($user->id)->toArray();

        return $this->sendToTokens($tokens, $notification, $data);
    }

    /**
     * Отправить уведомления нескольким пользователям.
     *
     * @param  Collection<User>  $users
     * @param  array<string, string>  $notification
     * @param  array<string, mixed>  $data
     */
    public function sendToUsers(Collection $users, array $notification, array $data = []): array
    {
        $userIds = $users->pluck('id')->toArray();

        Log::info('Sending push notification to multiple users', [
            'user_ids' => $userIds,
            'users_count' => count($userIds),
            'title' => $notification['title'] ?? '',
            'body' => $notification['body'] ?? '',
            'data' => $data,
        ]);

        $tokens = UserFcmToken::whereIn('user_id', $userIds)
            ->whereNotNull('token')
            ->whereHas('user', fn ($q) => $q->where('push_enabled', true))
            ->pluck('token')
            ->toArray();

        return $this->sendToTokens($tokens, $notification, $data);
    }

    /**
     * Отправить уведомление на FCM topic.
     *
     * @param  array<string, string>  $notification
     * @param  array<string, mixed>  $data
     */
    public function sendToTopic(string $topic, array $notification, array $data = []): array
    {
        $title = $notification['title'] ?? 'Уведомление';
        $body = $notification['body'] ?? 'У вас есть новое уведомление';

        Log::info('Sending FCM notification to topic', [
            'topic' => $topic,
            'title' => $title,
        ]);

        try {
            $fcmNotification = Notification::create($title, $body);

            $messageData = array_filter(array_merge($data, [
                'timestamp' => now()->toISOString(),
                'sent_from' => 'storefront',
            ]), fn ($value) => $value !== null);

            $messageData = array_map(function ($value) {
                return is_array($value) || is_object($value) ? json_encode($value) : (string) $value;
            }, $messageData);

            $message = CloudMessage::new()
                ->withTopic($topic)
                ->withNotification($fcmNotification)
                ->withData($messageData)
                ->withAndroidConfig([
                    'ttl' => '3600s',
                    'priority' => 'high',
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                        'notification_priority' => 'PRIORITY_HIGH',
                        'channel_id' => 'lastochka_notifications',
                    ],
                ])
                ->withApnsConfig([
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'sound' => 'default.wav',
                            'content-available' => 1,
                        ],
                    ],
                ]);

            $this->messaging->send($message);

            Log::info('Topic notification sent successfully', [
                'topic' => $topic,
                'title' => $title,
            ]);

            return ['success' => 1, 'failure' => 0, 'topic' => $topic];
        } catch (MessagingException|FirebaseException $e) {
            Log::error('Firebase topic messaging error', [
                'topic' => $topic,
                'error' => $e->getMessage(),
                'title' => $title,
            ]);

            return ['success' => 0, 'failure' => 1, 'topic' => $topic, 'error' => $e->getMessage()];
        }
    }

    /**
     * Отправить уведомления на конкретные FCM токены.
     *
     * @param  array<string>  $tokens
     * @param  array<string, string>  $notification
     * @param  array<string, mixed>  $data
     */
    public function sendToTokens(array $tokens, array $notification, array $data = []): array
    {
        if (empty($tokens)) {
            Log::warning('No FCM tokens found for notification');

            return ['success' => 0, 'failure' => 0, 'total' => 0];
        }

        $title = $notification['title'] ?? 'Уведомление';
        $body = $notification['body'] ?? 'У вас есть новое уведомление';

        Log::info('Sending FCM notifications', [
            'tokens_count' => count($tokens),
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        try {
            $fcmNotification = Notification::create($title, $body);

            // Добавляем дополнительные данные и конвертируем всё в строки
            $messageData = array_merge($data, [
                'timestamp' => now()->toISOString(),
                'sent_from' => 'storefront',
            ]);

            // Firebase требует все значения в data как строки
            $messageData = array_map(function ($value) {
                return is_array($value) || is_object($value) ? json_encode($value) : (string) $value;
            }, $messageData);

            // Создаем базовое сообщение
            $message = CloudMessage::new()
                ->withNotification($fcmNotification)
                ->withData($messageData);

            // Настройки для Android
            $message = $message->withAndroidConfig([
                'ttl' => '3600s',
                'priority' => 'high',
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'notification_priority' => 'PRIORITY_HIGH',
                    'channel_id' => 'lastochka_notifications',
                ],
                'data' => $messageData,
            ]);

            // Настройки для iOS (APNS)
            $message = $message->withApnsConfig([
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'alert' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'sound' => 'default.wav',
                        'content-available' => 1,
                    ],
                    'data' => $messageData,
                ],
            ]);

            $response = $this->messaging->sendMulticast($message, $tokens);

            Log::info('Firebase FCM response', [
                'success_count' => $response->successes()->count(),
                'failure_count' => $response->failures()->count(),
                'total_tokens' => count($tokens),
            ]);

            // Обработка ошибок и удаление недействительных токенов
            if ($response->hasFailures()) {
                $this->handleFailures($response->failures());
            }

            // Обновляем время последней отправки для успешно отправленных токенов
            $this->markTokensAsSent($tokens, $response->failures());

            return [
                'success' => $response->successes()->count(),
                'failure' => $response->failures()->count(),
                'total' => count($tokens),
            ];
        } catch (MessagingException|FirebaseException $e) {
            Log::error('Firebase messaging error', [
                'error' => $e->getMessage(),
                'tokens_count' => count($tokens),
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => 0,
                'failure' => count($tokens),
                'total' => count($tokens),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить токены пользователей по критериям сегментации.
     *
     * @param  array<string, mixed>  $segmentCriteria
     * @return array<string>
     */
    private function getTokensBySegment(array $segmentCriteria): array
    {
        $query = UserFcmToken::query()
            ->whereNotNull('token')
            ->whereNotNull('user_id');

        // Применяем фильтры сегментации через связь с пользователями
        $query->whereHas('user', function ($userQuery) use ($segmentCriteria) {
            $userQuery->where('push_enabled', true);

            // Пример фильтров:
            if (isset($segmentCriteria['city'])) {
                $userQuery->where('city', $segmentCriteria['city']);
            }

            if (isset($segmentCriteria['registration_after'])) {
                $userQuery->where('created_at', '>', $segmentCriteria['registration_after']);
            }

            if (isset($segmentCriteria['last_login_after'])) {
                $userQuery->where('last_login_at', '>', $segmentCriteria['last_login_after']);
            }

            if (isset($segmentCriteria['has_orders']) && $segmentCriteria['has_orders']) {
                $userQuery->whereHas('orders');
            }

            // Можно добавить другие критерии по необходимости
        });

        return $query->pluck('token')->toArray();
    }

    /**
     * Обработка ошибок Firebase.
     */
    private function handleFailures($failures): void
    {
        foreach ($failures->getItems() as $failure) {
            $token = $failure->target()->value();
            $error = $failure->error()->getMessage();

            Log::warning('Firebase notification failure', [
                'token' => $token,
                'error' => $error,
            ]);

            // Удаляем недействительные токены
            if ($this->isInvalidTokenError($error)) {
                $this->removeInvalidToken($token);
            }
        }
    }

    /**
     * Проверка, является ли ошибка связанной с недействительным токеном.
     */
    private function isInvalidTokenError(string $error): bool
    {
        return in_array($error, [
            'The registration token is not a valid FCM registration token',
            'The registration token is not registered for the topic',
            'Requested entity was not found.',
            'Invalid registration token',
        ], true);
    }

    /**
     * Удаление недействительного токена из базы данных.
     */
    private function removeInvalidToken(string $token): void
    {
        $deleted = UserFcmToken::where('token', $token)->delete();

        if ($deleted) {
            Log::info('Removed invalid FCM token', ['token' => $token]);
        }
    }

    /**
     * Обновление времени последней отправки для успешно отправленных токенов.
     *
     * @param  array<string>  $allTokens
     */
    private function markTokensAsSent(array $allTokens, $failures): void
    {
        $failedTokens = [];
        foreach ($failures->getItems() as $failure) {
            $failedTokens[] = $failure->target()->value();
        }

        $successfulTokens = array_diff($allTokens, $failedTokens);

        if (! empty($successfulTokens)) {
            UserFcmToken::whereIn('token', $successfulTokens)
                ->update(['last_sent_at' => now()]);

            Log::info('Marked FCM tokens as sent', [
                'successful_tokens_count' => count($successfulTokens),
            ]);
        }
    }
}
