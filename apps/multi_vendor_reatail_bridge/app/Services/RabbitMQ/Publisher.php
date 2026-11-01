<?php

namespace App\Services\RabbitMQ;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;

class Publisher
{
    /**
     * @var AMQPChannel
     */
    protected $channel;

    /**
     * @var array
     */
    protected $config;

    /**
     * @param AMQPChannel $channel
     * @param array $config
     */
    public function __construct(AMQPChannel $channel, array $config)
    {
        $this->channel = $channel;
        $this->config = $config;
    }

    /**
     * Публикует сообщение в RabbitMQ.
     *
     * @param string $entityType Тип сущности (products, categories, orders, customers, users, shops)
     * @param string $eventType Тип события (created, updated, deleted, и т.д.)
     * @param array $data Данные для публикации
     * @return bool
     */
    public function publish(string $entityType, string $eventType, array $data): bool
    {
        if (!isset($this->config[$entityType])) {
            Log::error("Неизвестный тип сущности: {$entityType}");
            return false;
        }

        $config = $this->config[$entityType];
        $exchange = $config['exchange'];
        $exchangeType = $config['exchange_type'];
        $routingKey = $eventType;

        try {
            // Объявляем exchange, если его еще нет
            $this->channel->exchange_declare(
                $exchange,
                $exchangeType,
                false,
                true,
                false
            );

            // Создаем сообщение
            $message = new AMQPMessage(
                json_encode($data),
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                ]
            );

            // Публикуем сообщение
            $this->channel->basic_publish($message, $exchange, $routingKey);

            Log::info("Сообщение опубликовано: {$routingKey}", ['data' => $data]);

            return true;
        } catch (\Exception $e) {
            Log::error("Ошибка при публикации сообщения: {$e->getMessage()}", [
                'exception' => $e,
                'entity_type' => $entityType,
                'event_type' => $eventType,
                'data' => $data,
            ]);

            return false;
        }
    }
}
