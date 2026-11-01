<?php

namespace App\Exceptions\RetailBridge;

class RetailCRMException extends RetailBridgeException
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
        parent::__construct($message, 'retailcrm', $contextData, $code, $previous);
    }
}
