<?php

namespace App\Services\Outbox;

use App\Models\System\EventOutbox;
use App\Services\RabbitMQ\RabbitMQConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

class OutboxPublisher
{
    public function __construct(protected RabbitMQConnection $connection)
    {
    }

    public function publishBatch(string $entityType, ?int $batchSize = null): int
    {
        $batch = $batchSize ?? (int) config('rabbitmq-events.outbox.batch_size', 100);

        return DB::transaction(function () use ($entityType, $batch) {
            $events = EventOutbox::unpublished()
                ->forEntity($entityType)
                ->orderBy('created_at')
                ->limit($batch)
                ->lockForUpdate()
                ->get();

            if ($events->isEmpty()) {
                return 0;
            }

            Log::info("Found {$events->count()} unpublished events for {$entityType}");

            $published = 0;
            $channel = $this->connection->channel();
            $exchange = config('rabbitmq-events.exchange_name', 'orders');

            foreach ($events as $event) {
                try {
                    $message = new AMQPMessage(
                        json_encode($event->payload),
                        [
                            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                            'content_type' => 'application/json',
                            'message_id' => $event->event_id,
                            'timestamp' => $event->created_at?->timestamp ?? time(),
                            'app_id' => config('rabbitmq-events.source', 'app'),
                        ]
                    );

                    $routingKey = $event->routing_key ?: $event->event_type;
                    $channel->basic_publish($message, $exchange, $routingKey);
                    $published++;

                    Log::info("Published event {$event->event_id}", [
                        'routing_key' => $routingKey,
                        'entity_type' => $entityType,
                        'id' => $event->id,
                        'payload' => $event->payload
                    ]);
                } catch (\Throwable $e) {
                    Log::error("Failed to publish event {$event->event_id}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'event_id' => $event->id
                    ]);
                    break;
                }
            }

            if ($published > 0) {
                $ids = $events->take($published)->pluck('id');
                EventOutbox::whereIn('id', $ids)->update(['published_at' => now()]);
            }

            return $published;
        });
    }
}
