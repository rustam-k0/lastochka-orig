<?php

namespace App\Exceptions\RetailBridge;

use Exception;
use Illuminate\Support\Facades\Log;

class RetailBridgeException extends Exception
{
    /**
     * @var string
     */
    protected $context;
    
    /**
     * @var array
     */
    protected $contextData;
    
    /**
     * @param string $message
     * @param string $context
     * @param array $contextData
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message,
        string $context = 'general',
        array $contextData = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->context = $context;
        $this->contextData = $contextData;
        
        // Логируем ошибку в соответствующий канал
        $this->logError();
    }
    
    /**
     * Логирует ошибку в соответствующий канал.
     *
     * @return void
     */
    protected function logError(): void
    {
        // Определяем канал логирования в зависимости от контекста
        $channel = match ($this->context) {
            'retailcrm' => 'retailcrm',
            'rabbitmq' => 'rabbitmq',
            'mapping' => 'mapping',
            default => 'stack',
        };
        
        // Формируем контекст для логирования
        $logContext = array_merge($this->contextData, [
            'exception' => $this,
            'trace' => $this->getTraceAsString(),
        ]);
        
        // Логируем ошибку
        Log::channel($channel)->error($this->getMessage(), $logContext);
    }
}
