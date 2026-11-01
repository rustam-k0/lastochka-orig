<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\RabbitMQ\Handlers\AnnouncementNotificationHandler;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;

class ConsumeAnnouncementNotifications extends Command
{
    protected $signature = 'notifications:consume {--queue=storefront_announcement_notifications}';
    protected $description = 'Consume announcement notifications from RabbitMQ';

    public function handle(AnnouncementNotificationHandler $handler): void
    {
        $this->info('Starting announcement notifications consumer...');

        $connection = new AMQPStreamConnection(
            config('rabbitmq-events.connection.host'),
            config('rabbitmq-events.connection.port'),
            config('rabbitmq-events.connection.user'),
            config('rabbitmq-events.connection.password')
        );

        $channel = $connection->channel();

        // Настройка очереди
        $queueName = $this->option('queue');
        $exchangeName = 'app_events';
        $routingKey = 'announcement.notification.notification_sent';

        $channel->exchange_declare($exchangeName, 'topic', false, true, false);
        $channel->queue_declare($queueName, false, true, false, false);
        $channel->queue_bind($queueName, $exchangeName, $routingKey);

        $this->info("Listening for messages on queue: {$queueName}");
        $this->info("Exchange: {$exchangeName}, Routing key: {$routingKey}");

        $callback = function (AMQPMessage $msg) use ($handler) {
            try {
                $body = json_decode($msg->getBody(), true);

                Log::info('Processing announcement notification', [
                    'routing_key' => $msg->getRoutingKey(),
                    'body' => $body
                ]);

                // Добавляем routing key в структуру для handler'а
                $eventData = [
                    'data' => $body,
                    'metadata' => [
                        'routing_key' => $msg->getRoutingKey()
                    ]
                ];

                $result = $handler->handle($eventData);

                if ($result === 'ack') {
                    $msg->ack();
                    $this->info("✅ Message processed successfully");
                } else {
                    $msg->nack(false, true);
                    $this->error("❌ Message rejected, requeuing");
                }

            } catch (\Exception $e) {
                Log::error('Failed to process announcement notification', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                $msg->nack(false, false); // Reject without requeue
                $this->error("❌ Message processing failed: " . $e->getMessage());
            }
        };

        $channel->basic_consume($queueName, '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }
}
