<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;

class AnnouncementNotificationTransformer implements EventTransformer
{
    public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
    {
        // Не отправляем события - только принимаем
        return [];
    }

    public function fromEvent(array $eventData): array
    {
        // Для уведомлений не создаём модель - только обрабатываем через handler
        return [];
    }

    public function validate(array $data): bool
    {
        return true; // Валидация в handler
    }
}