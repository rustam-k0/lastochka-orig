<?php

namespace App\Services\EventHandlers;

use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

abstract class BaseEventHandler
{
    /**
     * @var Publisher
     */
    protected $publisher;
    
    /**
     * @param Publisher $publisher
     */
    public function __construct(Publisher $publisher)
    {
        $this->publisher = $publisher;
    }
    
    /**
     * Обрабатывает событие из RabbitMQ.
     *
     * @param array $data Данные события
     * @param AMQPMessage $message Сообщение из RabbitMQ
     */
    abstract public function handle(array $data, AMQPMessage $message);
    
    /**
     * Получает тип события из routing key.
     *
     * @param AMQPMessage $message
     * @return string|null
     */
    protected function getEventType(AMQPMessage $message): ?string
    {
        $routingKey = $message->getRoutingKey();
        $parts = explode('.', $routingKey);
        
        return $parts[1] ?? null;
    }
    
    /**
     * Получает тип сущности из routing key.
     *
     * @param AMQPMessage $message
     * @return string|null
     */
    protected function getEntityType(AMQPMessage $message): ?string
    {
        $routingKey = $message->getRoutingKey();
        $parts = explode('.', $routingKey);
        
        return $parts[0] ?? null;
    }
    
    /**
     * Логирует информацию о событии.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Log::info($message, $context);
    }
    
    /**
     * Логирует ошибку.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function logError(string $message, array $context = []): void
    {
        Log::error($message, $context);
    }
}
