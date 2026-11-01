<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;

/**
 * Заглушка для entity b2c_events (LASTOCHKI-JAMI-11659).
 *
 * Публикация идёт вручную через EventOutbox (AnalyticsIngestService),
 * consume отсутствует. Transformer нужен только потому, что
 * ModelEventObserver требует ключ transformer у каждой enabled entity.
 */
final class B2cEventTransformer implements EventTransformer
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
