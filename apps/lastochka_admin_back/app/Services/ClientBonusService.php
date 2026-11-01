<?php

namespace App\Services;

use App\Models\ClientBonusTransaction;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Support\Str;

class ClientBonusService
{
    /**
     * Публикует команду bonus_operation.requested в outbox.
     *
     * Команда, а не факт: баланс живёт в storefront_back, он применяет операцию
     * и возвращает результат событием bonus_operation.created или .rejected.
     * Routing key изолирован от факта, иначе модули консюмили бы события друг друга.
     */
    public function publishOperationRequested(ClientBonusTransaction $tx): void
    {
        $client = $tx->client;
        $eventId = Str::uuid()->toString();

        EventOutbox::create([
            'entity_type' => 'client_bonus_transactions',
            'entity_id' => $tx->id,
            'event_type' => 'requested',
            'event_id' => $eventId,
            'routing_key' => 'bonus_operation.requested',
            'payload' => [
                'event_id' => $eventId,
                'event_timestamp' => now()->toIso8601String(),
                'event_source' => config('rabbitmq-events.source'),
                'source' => config('rabbitmq-events.source'),
                'operation_id' => $tx->operation_id,
                'client_id' => (string) $tx->client_id,
                'phone' => $client ? $client->phone : null,
                'loyalty_id' => $client ? $client->loyalty_id : null,
                'type' => $tx->type,
                'amount' => (int) $tx->amount,
                'comment' => (string) $tx->description,
            ],
        ]);
    }
}
