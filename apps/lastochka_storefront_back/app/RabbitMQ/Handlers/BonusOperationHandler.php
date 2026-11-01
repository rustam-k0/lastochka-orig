<?php

declare(strict_types=1);

namespace App\RabbitMQ\Handlers;

use App\Services\Bonus\BonusService;

final class BonusOperationHandler
{
    public function __construct(private readonly BonusService $bonusService) {}

    /**
     * Обрабатывает входящее событие bonus_operation.created (офлайн-операция из 1С/кассы).
     *
     * @param  array<string, mixed>  $eventData
     */
    public function handle(array $eventData): string
    {
        $payload = $eventData['payload'] ?? $eventData;

        // Свои же факты возвращаются по этому же routing key. Идемпотентность по
        // operation_id их и так отсекает, но нет смысла гонять их через сервис.
        $eventSource = $payload['event_source'] ?? $eventData['event_source'] ?? null;
        if ($eventSource !== null && $eventSource === config('rabbitmq-events.source')) {
            return 'ack';
        }

        $this->bonusService->applyExternalOperation([
            'operation_id'     => $payload['operation_id'] ?? null,
            'loyalty_id'       => $payload['loyalty_id'] ?? null,
            'type'             => $payload['type'] ?? null,
            'amount'           => $payload['amount'] ?? 0,
            'operation_source' => $payload['operation_source'] ?? 'offline',
        ]);

        return 'ack';
    }
}
