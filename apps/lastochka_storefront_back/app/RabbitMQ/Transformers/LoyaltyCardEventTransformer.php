<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use Illuminate\Database\Eloquent\Model;

/**
 * Трансформер для entity 'loyalty_cards' (config/rabbitmq-events.php).
 *
 * Используется ТОЛЬКО для validate() входящего события `loyalty_card.confirmed`
 * от 1c_parser — EventConsumer::processEvent() вызывает validate() ДО роутинга
 * на кастомный handler (LoyaltyCardConfirmedHandler), поэтому requiredFields
 * обязаны матчиться с реальной формой payload'а: `{event_id, event_type,
 * event_source, loyalty_id, card_number}` (card_number обязателен,
 * loyalty_id — опционален).
 *
 * Публикация `loyalty_card.created` идёт напрямую через EventOutbox::create()
 * в LoyaltyCardService::publishCreated() — toEvent()/fromEvent() тут не
 * используются, оставлены минимальными заглушками (см. BonusOperationEventTransformer).
 */
final class LoyaltyCardEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'card_number'];
    }

    /**
     * Не используется: публикация loyalty_card.created — напрямую через
     * EventOutbox (LoyaltyCardService::publishCreated), минуя transformer.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $model, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Не используется: входящее событие обрабатывается кастомным
     * LoyaltyCardConfirmedHandler, минуя стандартный пайплайн fromEvent().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        return [];
    }
}
