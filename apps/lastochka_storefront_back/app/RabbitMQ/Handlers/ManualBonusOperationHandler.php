<?php

declare(strict_types=1);

namespace App\RabbitMQ\Handlers;

use App\Services\Bonus\BonusService;

final class ManualBonusOperationHandler
{
    public function __construct(private readonly BonusService $bonusService) {}

    /**
     * Обрабатывает команду bonus_operation.requested из админки.
     *
     * Всегда ack: невалидная команда превращается в bonus_operation.rejected
     * внутри сервиса, повторять её в DLQ бессмысленно.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function handle(array $eventData): string
    {
        $payload = $eventData['payload'] ?? $eventData;

        $this->bonusService->applyManualOperation([
            'operation_id' => $payload['operation_id'] ?? null,
            'type'         => $payload['type'] ?? null,
            'amount'       => $payload['amount'] ?? 0,
            'comment'      => $payload['comment'] ?? '',
            'phone'        => $payload['phone'] ?? null,
            'loyalty_id'   => $payload['loyalty_id'] ?? null,
        ]);

        return 'ack';
    }
}
