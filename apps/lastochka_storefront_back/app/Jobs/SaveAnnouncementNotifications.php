<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SaveAnnouncementNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array{title: string, short_description: string, body: string|null, image_url: string|null, promo_code: string|null, announcement_id: string|null}  $announcementData
     */
    public function __construct(
        private array $announcementData,
    ) {}

    public function handle(): void
    {
        $title = $this->announcementData['title'];

        Log::info('Saving announcement notifications for all users', [
            'title' => $title,
            'announcement_id' => $this->announcementData['announcement_id'] ?? null,
        ]);

        $deepLink = $this->announcementData['announcement_id']
            ? "announcement/{$this->announcementData['announcement_id']}"
            : null;

        $announcementId = $this->announcementData['announcement_id'] ?? null;
        $inserted = 0;

        User::query()
            ->select(['id', 'promo_enabled'])
            ->where('promo_enabled', true)
            ->chunkById(500, function ($users) use ($title, $deepLink, $announcementId, &$inserted) {
                if ($users->isEmpty()) {
                    return;
                }

                $records = $users->map(fn (User $user) => [
                    'user_id' => $user->id,
                    'type' => NotificationType::Promo->value,
                    'title' => $title,
                    'short_description' => $this->announcementData['short_description'],
                    'body' => $this->announcementData['body'],
                    'image_url' => $this->announcementData['image_url'],
                    'promo_code' => $this->announcementData['promo_code'],
                    'deep_link' => $deepLink,
                    'announcement_id' => $announcementId,
                    'is_read' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->toArray();

                UserNotification::insert($records);
                $inserted += count($records);
            });

        Log::info('Announcement notifications saved', [
            'title' => $title,
            'users_count' => $inserted,
        ]);

        // Отдельной джобой: падение почты не должно ретраить bulk insert выше.
        SendAnnouncementEmails::dispatch($this->announcementData);
    }
}
