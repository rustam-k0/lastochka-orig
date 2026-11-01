<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\UserNotificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Отправка email-уведомлений пользователю витрины.
 * Сигнатура зеркалит FirebaseNotificationService::sendToUser().
 */
final class EmailNotificationService
{
    /**
     * @param  array<string, string>  $notification  ['title' => ..., 'body' => ...]
     * @param  array<string, mixed>  $data  тот же payload, что уходит в push
     * @return array{sent: int, skipped: string|null}
     */
    public function sendToUser(User $user, array $notification, array $data = []): array
    {
        if (! ($user->email_enabled ?? false)) {
            Log::info('Email notification skipped: user disabled email channel', [
                'user_id' => $user->id,
            ]);

            return ['sent' => 0, 'skipped' => 'email_disabled'];
        }

        if (! $user->hasDeliverableEmail()) {
            Log::info('Email notification skipped: user has no deliverable address', [
                'user_id' => $user->id,
            ]);

            return ['sent' => 0, 'skipped' => 'no_email'];
        }

        $title = $notification['title'] ?? 'Уведомление';
        $body = $notification['body'] ?? '';

        Mail::to($user->email)->queue(
            new UserNotificationMail($title, $body, $this->resolveOrderUrl($data))
        );

        Log::info('Email notification queued', [
            'user_id' => $user->id,
            'title' => $title,
        ]);

        return ['sent' => 1, 'skipped' => null];
    }

    /**
     * Ссылка на заказ для кнопки в письме.
     * Нет шаблона в конфиге или order_id в payload — кнопки не будет.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveOrderUrl(array $data): ?string
    {
        $template = config('notifications.email.order_url_template');
        $orderId = $data['order_id'] ?? null;

        if (! $template || ! $orderId) {
            return null;
        }

        return str_replace('{id}', (string) $orderId, (string) $template);
    }
}
