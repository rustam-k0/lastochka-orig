<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\RabbitMQ\Debug\LoggingEventConsumer;
use Comrades\RabbitMQEvents\Commands\ConsumeEvents;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Comrades\RabbitMQEvents\Services\RabbitMQConnection;
use Illuminate\Support\Facades\Log;

/**
 * rabbitmq:consume витрины: цикл потребления целиком из пакета (несколько
 * очередей одним процессом для rabbitmq:consume-all, адресные ретраи,
 * dead letters, маскирование логов), а потребитель подменяется на
 * LoggingEventConsumer: лог в RabbitMQ Debug Dashboard и orphan-recover
 * метаданных. Заменяет команду пакета по имени.
 *
 * Раньше команда копировала весь цикл пакета с сигнатурой {entity}: из-за
 * этого consume-all не мог отдать общему процессу несколько очередей, а копия
 * отставала от пакета (неадресный ретрай без x-retry-queue, без dead letters).
 */
final class ConsumeEventsCommand extends ConsumeEvents
{
    protected $description = 'Consume events from RabbitMQ queue(s) with debug logging';

    public function handle(RabbitMQConnection $connection): int
    {
        if (! method_exists(ConsumeEvents::class, 'makeConsumer')) {
            // Пакет старее хука: его цикл создаст обычный EventConsumer без
            // debug-лога и orphan-recover. Не падаем, но шумим: нужен bump пакета.
            $this->warn('comrades/laravel-rabbitmq-events without makeConsumer() hook: debug logging and orphan recover are disabled, bump the package');
            Log::warning('rabbitmq:consume: package without makeConsumer() hook, LoggingEventConsumer is not used');
        }

        return parent::handle($connection);
    }

    /**
     * Потребитель сущности для цикла пакета: с debug-логом и orphan-recover.
     */
    protected function makeConsumer(string $entity, array $entityConfig, string $queueName): EventConsumer
    {
        return new LoggingEventConsumer($entity, $entityConfig, $queueName);
    }
}
