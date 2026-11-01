<?php

namespace App\Transformers;

use App\Events\AnnouncementNotificationRequested;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AnnouncementNotificationEventTransformer implements EventTransformer
{
    /**
     * Преобразовать событие AnnouncementNotificationRequested в формат для RabbitMQ
     */
    public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
    {
        // Получаем данные из события (доступ через eventData)
        $data = [];

        // Если есть дополнительные данные события (eventData передается RabbitMQ events)
        if (request()->has('eventData')) {
            $data = request()->get('eventData');
        }

        // Используем модель для получения данных
        $announcement = $model; // Это Announcement модель

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => now()->toIso8601String(),
            'source' => config('rabbitmq-events.source'),
            'shop_code' => config('rabbitmq-events.shop_code'),

            // Данные уведомления
            'announcement_id' => $announcement->id,
            'target' => $data['target'] ?? 'ALL_USERS',
            'segment_criteria' => $data['segment_criteria'] ?? [],

            // Данные об акции из модели
            'announcement_data' => [
                'title' => $announcement->title,
                'short_description' => $announcement->short_description,
                'description' => $announcement->description,
                'image_url' => PathNormalizer::url($announcement->image?->path),
                'promocode' => $announcement->promocode ? [
                    'code' => $announcement->promocode->code,
                    'discount_percent' => $announcement->promocode->discount_percent,
                ] : null,
            ],

            // Метаданные
            'created_at' => $data['created_at'] ?? now()->toIso8601String(),
            'notification_type' => 'announcement_push',
        ];
    }

    /**
     * Преобразовать входящее событие в атрибуты модели
     * (не используется для исходящих уведомлений)
     */
    public function fromEvent(array $data): array
    {
        return [
            'announcement_id' => $data['announcement_id'] ?? null,
            'target' => $data['target'] ?? 'ALL_USERS',
            'segment_criteria' => $data['segment_criteria'] ?? [],
            'announcement_data' => $data['announcement_data'] ?? [],
        ];
    }

    /**
     * Валидация данных события
     * (требуется интерфейсом EventTransformer)
     */
    public function validate(array $data): bool
    {
        // Простая валидация - проверяем наличие обязательных полей
        return isset($data['announcement_id']) &&
               isset($data['target']) &&
               isset($data['announcement_data']);
    }
}