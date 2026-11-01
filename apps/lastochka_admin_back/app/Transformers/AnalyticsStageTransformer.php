<?php

namespace App\Transformers;

use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;

/**
 * Транформер-заглушка для publish-only аналитической entity order_stages.
 *
 * Payload собирает App\Services\AnalyticsEvents и передаёт в
 * CustomEventPublisher::dispatch() как customPayload — toEvent не участвует
 * (Eloquent-events у entity выключены, events => []).
 */
final class AnalyticsStageTransformer implements EventTransformer
{
    public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [];
    }

    public function fromEvent(array $eventData): array
    {
        return [];
    }

    public function validate(array $eventData): bool
    {
        return true;
    }
}
