<?php

namespace App\Services;

use App\Models\Announcement;
use App\Events\AnnouncementNotificationRequested;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function sendAnnouncementNotification(
        Announcement $announcement,
        string $target = 'ALL_USERS',
        array $segmentCriteria = []
    ): array {

        // Валидация
        if (!$announcement->is_active) {
            throw new \InvalidArgumentException('Нельзя отправить уведомление для неактивной акции');
        }

        // Генерация события для RabbitMQ
        $eventData = [
            'announcement_id' => $announcement->id,
            'target' => $target,
            'segment_criteria' => $segmentCriteria,
            'announcement_data' => [
                'title' => $announcement->title,
                'short_description' => $announcement->short_description,
                'description' => $announcement->description,
                'image_url' => $announcement->image?->path,
                'promocode' => $announcement->promocode ? [
                    'code' => $announcement->promocode->code,
                    'discount_percent' => $announcement->promocode->discount_percent,
                ] : null,
            ],
            'created_at' => now()->toISOString(),
        ];

        Log::info('Creating AnnouncementNotificationRequested event', [
            'announcement_id' => $announcement->id,
            'announcement_class' => get_class($announcement),
            'event_data' => $eventData,
        ]);

        event(new AnnouncementNotificationRequested($announcement, $eventData));

        Log::info('Announcement notification requested', [
            'announcement_id' => $announcement->id,
            'target' => $target,
            'title' => $announcement->title,
        ]);

        return [
            'success' => true,
            'target' => $target,
            'announcement_id' => $announcement->id,
        ];
    }
}