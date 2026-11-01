<?php

namespace App\Console\Commands\RabbitMQ;

use App\Services\EventHandlers\OrderEventHandler;
use App\Services\RabbitMQ\RabbitMQConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rb:consume-events {entity=orders} {--queue=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume events from RabbitMQ queue (aligned with laravel-rabbitmq-events)';

    protected bool $shouldStop = false;
    protected int $processedCount = 0;

    public function handle(RabbitMQConnection $connection, OrderEventHandler $orderHandler): int
    {
        $entity = (string) $this->argument('entity');
        $queueName = $this->option('queue') ?? config("rabbitmq-events.inbound_queues.{$entity}");

        if (!$queueName) {
            $this->error("Queue not configured for entity: {$entity}");
            return self::FAILURE;
        }

        $this->info("Starting consumer for entity: {$entity}");
        $this->info("Queue: {$queueName}");

        // Prefetch & restart thresholds
        $prefetchCount = (int) config('rabbitmq-events.consumer.prefetch_count', 1);
        $restartAfter = (int) config('rabbitmq-events.consumer.restart_after', 1000);
        $memoryLimit = (int) config('rabbitmq-events.consumer.memory_limit', 128) * 1024 * 1024;

        // Get routing key patterns
        $entityConfig = config("rabbitmq-events.entities.{$entity}") ?? [];
        $routingKeyPatterns = $entityConfig['routing_key_patterns'] ?? ['order.*'];
        if (!is_array($routingKeyPatterns)) {
            $routingKeyPatterns = [$routingKeyPatterns];
        }

        try {
            DB::connection()->disableQueryLog();

            $channel = $connection->channel();
            $exchange = config('rabbitmq-events.exchange_name', 'orders');

            // Declare queue
            $channel->queue_declare(
                $queueName,
                false,
                true,
                false,
                false
            );

            // Bind routing patterns
            foreach ($routingKeyPatterns as $pattern) {
                $channel->queue_bind($queueName, $exchange, $pattern);
            }

            // QoS
            $channel->basic_qos(null, $prefetchCount, false);

            $handler = match ($entity) {
                'orders' => $orderHandler,
                default => $orderHandler,
            };

            $channel->basic_consume(
                $queueName,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $msg) use ($handler, $restartAfter, $memoryLimit) {
                    try {
                        $data = json_decode($msg->getBody(), true);
                        if (!is_array($data)) {
                            // invalid JSON -> ack to drop
                            $msg->ack();
                            return;
                        }

                        // Fill event_type from routing key if absent
                        $routingKey = $msg->getRoutingKey();
                        if ($routingKey && !isset($data['event_type'])) {
                            $parts = explode('.', $routingKey);
                            $data['event_type'] = end($parts);
                        }

                        $result = $handler->handle($data, $msg);

                        // Ack always on logical results
                        $msg->ack();
                        $this->processedCount++;

                        Log::info("Message processed successfully", [
                            'routing_key' => $routingKey,
                            'message_id' => $msg->has('message_id') ? $msg->get('message_id') : null,
                            'result' => $result
                        ]);

                        // Restart thresholds
                        $currentMemory = memory_get_usage(true);
                        if ($currentMemory >= $memoryLimit || $this->processedCount >= $restartAfter) {
                            $msg->getChannel()->basic_cancel($msg->getConsumerTag());
                        }
                    } catch (\Throwable $e) {
                        Log::error('Error handling message', [
                            'error' => $e->getMessage(),
                        ]);
                        // Nack without requeue -> DLX/backoff
                        $msg->nack(false);
                    }
                }
            );

            while (!$this->shouldStop && $channel->is_consuming()) {
                $channel->wait();
            }

            $this->info('Consumer stopped');
            $this->info("Processed {$this->processedCount} messages");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Consumer fatal error', [
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        } finally {
            if (method_exists($connection, 'shutdown')) {
                $connection->shutdown();
            }
        }
    }
}
