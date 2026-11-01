<?php

namespace App\Transformers;

use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;

/**
 * Транформер-заглушка для publish-only аналитических entities
 * (picking / picking_items / delivery_stages / order_stages).
 *
 * Payload собирает AnalyticsEvents-хелпер и передаёт в
 * CustomEventPublisher::dispatch() как customPayload — toEvent не участвует
 * (Eloquent-events у этих entities выключены, events => []).
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
