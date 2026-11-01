<?php

namespace App\Console\Commands;

use App\RabbitMQ\Consumers\BonusOperationConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class ConsumeBonusOperationsCommand extends Command
{
    protected $signature = 'rabbitmq:consume-bonus-operations
                            {--once : Обработать одно сообщение и выйти}
                            {--timeout=0 : Таймаут ожидания сообщений (0 = бесконечно)}';

    protected $description = 'Consume бонусных операций (bonus operations) из RabbitMQ';

    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;
    private int $processedCount = 0;
    private int $maxMessages;
    private int $memoryLimit;
    private bool $shouldStop = false;

    public function handle(): int
    {
        $config = config('rabbitmq-events');
        $consumerConfig = $config['consumers']['bonus_operations'] ?? [];

        if (!($consumerConfig['enabled'] ?? true)) {
            $this->warn('Consumer бонусных операций отключён в конфигурации');
            return self::SUCCESS;
        }

        $this->maxMessages = $config['consumer']['restart_after'] ?? 100000;
        $this->memoryLimit = $config['consumer']['memory_limit'] ?? 256;

        $this->info('Запуск consumer бонусных операций...');

        $this->registerSignalHandlers();

        try {
            $this->connect($config['connection']);
            $this->declareQueue($consumerConfig);
            $this->consume($consumerConfig);
        } catch (Throwable $e) {
            $this->error("Ошибка: {$e->getMessage()}");
            Log::channel('rabbitmq-orders')->error("BonusOperationConsumer command error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        } finally {
            $this->disconnect();
        }

        $this->info("Обработано сообщений: {$this->processedCount}");
        return self::SUCCESS;
    }

    private function connect(array $config): void
    {
        $this->connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost']
        );

        $this->channel = $this->connection->channel();
        $this->info('Подключение к RabbitMQ установлено');
    }

    private function declareQueue(array $config): void
    {
        $queue = $config['queue'];
        $exchange = $config['exchange'];
        $routingKeys = $config['routing_keys'];
        $deadLetter = $config['dead_letter'] ?? [];

        $arguments = [];
        if (!empty($deadLetter['exchange'])) {
            $arguments['x-dead-letter-exchange'] = ['S', $deadLetter['exchange']];

            $this->channel->exchange_declare(
                $deadLetter['exchange'],
                'fanout',
                false,
                true,
                false
            );

            $this->channel->queue_declare(
                $deadLetter['queue'] ?? "{$queue}.dlq",
                false,
                true,
                false,
                false
            );

            $this->channel->queue_bind(
                $deadLetter['queue'] ?? "{$queue}.dlq",
                $deadLetter['exchange']
            );
        }

        $this->channel->queue_declare(
            $queue,
            false,
            true,
            false,
            false,
            false,
            $arguments
        );

        foreach ($routingKeys as $routingKey) {
            $this->channel->queue_bind($queue, $exchange, $routingKey);
        }

        $prefetch = $config['prefetch_count'] ?? 10;
        $this->channel->basic_qos(null, $prefetch, null);

        $this->info("Очередь '{$queue}' готова, привязки: " . implode(', ', $routingKeys));
    }

    private function consume(array $config): void
    {
        $queue = $config['queue'];
        $maxRetries = $config['retry']['max_attempts'] ?? 3;

        $consumer = app(BonusOperationConsumer::class);

        $callback = function (AMQPMessage $message) use ($consumer, $maxRetries, $queue) {
            $retryCount = $this->getRetryCount($message);

            try {
                $ack = $consumer->handle($message);

                if ($ack) {
                    $message->ack();
                } else {
                    if ($retryCount < $maxRetries - 1) {
                        $this->requeue($message, $retryCount + 1, $queue);
                    } else {
                        $message->nack(false, false);
                    }
                }

                $this->processedCount++;

            } catch (Throwable $e) {
                Log::channel('rabbitmq-orders')->error("Ошибка обработки бонусной операции", [
                    'error' => $e->getMessage(),
                    'retry_count' => $retryCount,
                ]);

                if ($retryCount < $maxRetries - 1) {
                    $this->requeue($message, $retryCount + 1);
                } else {
                    $message->nack(false, false);
                }
            }

            if ($this->shouldStop()) {
                $this->channel->basic_cancel($message->getConsumerTag());
            }
        };

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            $callback
        );

        $timeout = (int) $this->option('timeout');
        $once = $this->option('once');

        while ($this->channel->is_consuming() && !$this->shouldStop()) {
            try {
                // 0 = блокирующее ожидание; null у php-amqplib означает мгновенный poll (busy-loop на пустой очереди)
                $this->channel->wait(null, false, $timeout);

                if ($once) {
                    break;
                }
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                $this->info('Таймаут ожидания сообщений');
                break;
            }
        }
    }

    private function getRetryCount(AMQPMessage $message): int
    {
        $headers = $message->get_properties()['application_headers'] ?? null;

        if ($headers && $headers->getNativeData()) {
            return $headers->getNativeData()['x-retry-count'] ?? 0;
        }

        return 0;
    }

    private function requeue(AMQPMessage $message, int $retryCount, string $queue): void
    {
        // nack(requeue=true) вернул бы оригинал без заголовков: x-retry-count не растёт,
        // «ядовитое» сообщение редоставляется бесконечно вместо max_attempts попыток и DLQ
        $properties = $message->get_properties();
        $headers = isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable
            ? $properties['application_headers']->getNativeData()
            : [];
        $headers['x-retry-count'] = $retryCount;
        $properties['application_headers'] = new AMQPTable($headers);

        $this->channel->basic_publish(new AMQPMessage($message->getBody(), $properties), '', $queue);
        $message->ack();
    }

    private function shouldStop(): bool
    {
        if ($this->shouldStop) {
            return true;
        }

        if ($this->processedCount >= $this->maxMessages) {
            $this->info("Достигнут лимит сообщений: {$this->maxMessages}");
            return true;
        }

        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        if ($memoryUsage >= $this->memoryLimit) {
            $this->info("Достигнут лимит памяти: {$this->memoryLimit}MB");
            return true;
        }

        return false;
    }

    private function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        }
    }

    private function disconnect(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (Throwable $e) {
            // Ignore close errors
        }
    }
}
