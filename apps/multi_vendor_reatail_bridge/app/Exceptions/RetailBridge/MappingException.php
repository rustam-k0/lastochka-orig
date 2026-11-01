<?php

namespace App\Exceptions\RetailBridge;

class MappingException extends RetailBridgeException
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
        parent::__construct($message, 'mapping', $contextData, $code, $previous);
    }
}
