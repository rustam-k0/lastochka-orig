<?php

namespace App\Services;

use App\Models\Client;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Support\Str;

class ClientB2bService
{
    /**
     * Публикует выделенное событие customer.b2b_status_changed в outbox.
     * Routing key изолирован от generic customer.updated — чтобы RetailCRM не затёр флаг.
     */
    public function publishB2bStatusChanged(Client $client): void
    {
        EventOutbox::create([
            'entity_type' => 'customers',
            'entity_id' => $client->id,
            'event_type' => 'b2b_status_changed',
            'event_id' => Str::uuid()->toString(),
            'routing_key' => 'customer.b2b_status_changed',
            'payload' => [
                'event_id' => Str::uuid()->toString(),
                'event_timestamp' => now()->toIso8601String(),
                'id' => (string) $client->id,
                'source' => config('rabbitmq-events.source'),
                'phone' => $client->phone,
                'is_b2b' => (bool) $client->is_b2b,
            ],
        ]);
    }
}
