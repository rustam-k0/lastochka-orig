<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use Illuminate\Database\Eloquent\Model;

/**
 * Команда bonus_operation.requested от admin_back.
 *
 * Трансформер используется ТОЛЬКО для validate(): EventConsumer::processEvent()
 * вызывает его до роутинга на handler, поэтому requiredFields обязаны матчиться
 * с реальным payload'ом команды, а не с BonusOperationEventTransformer.
 * Наличие phone или loyalty_id проверяется уже в BonusService::applyManualOperation(),
 * потому что requiredFields не умеет выражать «хотя бы одно из двух».
 */
final class ManualBonusOperationTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'operation_id', 'type', 'amount', 'comment'];
    }

    /**
     * Не используется: команда только входящая.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $model, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Не используется: применение идёт через handler, а не через модельный upsert.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        return [];
    }
}
