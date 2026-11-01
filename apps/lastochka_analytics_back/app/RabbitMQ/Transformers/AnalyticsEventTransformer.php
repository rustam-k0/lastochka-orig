<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;

/**
 * Заглушка-трансформер для entity `events`.
 *
 * EventConsumer::processEvent вызывает validate() ДО роутинга в custom
 * handler. Аналитика принимает любые события шины — validate всегда true,
 * иначе незнакомый формат улетит в retry/DLQ вместо журнала _unparsed.
 */
final class AnalyticsEventTransformer implements EventTransformer
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
