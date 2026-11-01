<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use Illuminate\Database\Eloquent\Model;

/**
 * Трансформер для entity 'barcode_prefixes' (config/rabbitmq-events.php).
 *
 * Используется ТОЛЬКО для validate() входящего события `barcode_prefixes.updated`
 * от 1c_parser — EventConsumer::processEvent() вызывает validate() ДО роутинга
 * на кастомный handler (BarcodePrefixHandler), поэтому requiredFields обязаны
 * матчиться с реальной формой payload'а: `{event_id, event_type, event_source,
 * type, value}`.
 *
 * У нас нет outbound-публикации этой сущности (мастер префиксов — 1С) —
 * toEvent()/fromEvent() тут не используются, оставлены минимальными
 * заглушками (см. BonusOperationEventTransformer).
 */
final class BarcodePrefixEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'type', 'value'];
    }

    /**
     * Не используется: storefront не публикует barcode_prefixes обратно.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $model, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Не используется: входящее событие обрабатывается кастомным
     * BarcodePrefixHandler, минуя стандартный пайплайн fromEvent().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        return [];
    }
}
