<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Рассылка промо-анонса по email.
 *
 * Отдельная джоба, а не часть SaveAnnouncementNotifications: та пишет
 * UserNotification::insert() пачками без загрузки моделей, и при её трёх
 * попытках падение почты задваивало бы in-app уведомления по всей базе.
 */
final class SendAnnouncementEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array{title: string, short_description: string, body: string|null, image_url: string|null, promo_code: string|null, announcement_id: string|int|null}  $announcementData
     */
    public function __construct(
        private array $announcementData,
    ) {}

    public function handle(EmailNotificationService $emailNotificationService): void
    {
        $title = $this->announcementData['title'];
        $body = $this->announcementData['body'] ?: $this->announcementData['short_description'];
        $queued = 0;

        User::query()
            ->where('promo_enabled', true)
            ->where('email_enabled', true)
            ->whereNotNull('email')
            ->chunkById(500, function ($users) use ($emailNotificationService, $title, $body, &$queued) {
                foreach ($users as $user) {
                    if (! $user->canReceiveEmail(NotificationType::Promo)) {
                        continue;
                    }

                    try {
                        // Без order_id — в промо-письме кнопки заказа нет.
                        $emailNotificationService->sendToUser($user, [
                            'title' => $title,
                            'body' => $body,
                        ]);
                        $queued++;
                    } catch (\Throwable $e) {
                        Log::warning('announcement email failed', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('Announcement emails queued', [
            'title' => $title,
            'users_count' => $queued,
        ]);
    }
}
