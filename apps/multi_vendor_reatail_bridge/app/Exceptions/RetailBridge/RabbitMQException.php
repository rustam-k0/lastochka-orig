<?php

namespace App\Exceptions\RetailBridge;

class RabbitMQException extends RetailBridgeException
{
    /**
     * @param string $message
     * @param array $contextData
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message,
        array $contextData = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, 'rabbitmq', $contextData, $code, $previous);
    }
}
