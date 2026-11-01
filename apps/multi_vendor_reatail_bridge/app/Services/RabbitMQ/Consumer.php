<?php

namespace App\Services\RabbitMQ;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Illuminate\Support\Facades\Log;
use Exception;

class Consumer
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
     * Настраивает очереди и привязки для прослушивания сообщений.
     *
     * @param string $entityType Тип сущности (products, categories, orders, customers, users, shops)
     * @return bool
     */
    public function setup(string $entityType): bool
    {
        if (!isset($this->config[$entityType])) {
            Log::error("Неизвестный тип сущности: {$entityType}");
            return false;
        }
        
        $config = $this->config[$entityType];
        $exchange = $config['exchange'];
        $exchangeType = $config['exchange_type'];
        $queue = $config['queue'];
        $routingKeys = $config['routing_keys'];
        
        try {
            // Объявляем основной exchange
            $this->channel->exchange_declare(
                $exchange,
                $exchangeType,
                false,    // passive
                true,     // durable
                false     // auto_delete
            );
            
            // Объявляем основную очередь БЕЗ дополнительных параметров
            // чтобы избежать конфликта с существующими очередями
            $this->channel->queue_declare(
                $queue,
                false,    // passive
                true,     // durable
                false,    // exclusive
                false     // auto_delete
            );
            
            // Привязываем очередь к exchange с указанными routing keys
            foreach ($routingKeys as $routingKey) {
                $this->channel->queue_bind($queue, $exchange, $routingKey);
            }
            
            Log::info("Очередь настроена: {$queue}");
            
            return true;
        } catch (Exception $e) {
            Log::error("Ошибка при настройке очереди: {$e->getMessage()}", [
                'exception' => $e,
                'entity_type' => $entityType,
                'exchange' => $exchange ?? 'unknown',
                'queue' => $queue ?? 'unknown',
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Также выводим в консоль для отладки
            echo "ОШИБКА НАСТРОЙКИ ОЧЕРЕДИ: {$e->getMessage()}\n";
            echo "Exchange: " . ($exchange ?? 'unknown') . "\n";
            echo "Queue: " . ($queue ?? 'unknown') . "\n";
            
            return false;
        }
    }
    
    /**
     * Начинает прослушивание сообщений из указанной очереди.
     *
     * @param string $entityType Тип сущности (products, categories, orders, customers, users, shops)
     * @param callable $callback Функция обратного вызова для обработки сообщений
     * @return bool
     */
    public function consume(string $entityType, callable $callback): bool
    {
        if (!isset($this->config[$entityType])) {
            Log::error("Неизвестный тип сущности: {$entityType}");
            return false;
        }
        
        $config = $this->config[$entityType];
        $queue = $config['queue'];
        
        try {
            // Настраиваем обработчик сообщений
            $this->channel->basic_consume(
                $queue,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $message) use ($callback) {
                    try {
                        // Декодируем JSON-сообщение
                        $data = json_decode($message->getBody(), true);
                        
                        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                            throw new Exception('Invalid JSON: ' . json_last_error_msg());
                        }
                        
                        // ✅ ЛОГИРОВАНИЕ ВСЕХ ВХОДЯЩИХ СООБЩЕНИЙ
                        if (config('rabbitmq-events.logging.log_incoming_messages', true)) {
                            $logData = [
                                'routing_key' => $message->getRoutingKey(),
                                'exchange' => $message->getExchange(),
                                'delivery_tag' => $message->getDeliveryTag(),
                                'redelivered' => $message->isRedelivered(),
                                'data' => $data,
                                'timestamp' => now()->toIso8601String(),
                            ];
                            
                            // Добавляем сырой JSON если включено (может быть объемным)
                            if (config('rabbitmq-events.logging.log_raw_body', true)) {
                                $logData['raw_body'] = $message->getBody();
                            }
                            
                            Log::info('📨 Получено сообщение из RabbitMQ', $logData);
                        }
                        
                        // Получаем информацию о количестве попыток обработки
                        $headers = $message->get_properties();
                        $xDeathHeader = null;
                        
                        if (isset($headers['application_headers']) && $headers['application_headers'] instanceof AMQPTable) {
                            $appHeaders = $headers['application_headers']->getNativeData();
                            if (isset($appHeaders['x-death'])) {
                                $xDeathHeader = $appHeaders['x-death'];
                            }
                        }
                        
                        // Подсчитываем количество попыток
                        $retryCount = 0;
                        if ($xDeathHeader && is_array($xDeathHeader) && !empty($xDeathHeader)) {
                            $retryCount = $xDeathHeader[0]['count'] ?? 0;
                        }
                        
                        // Если превышено максимальное количество попыток (5), отправляем в мертвую очередь
                        if ($retryCount >= 5) {
                            Log::warning("Превышено максимальное количество попыток обработки сообщения", [
                                'message' => $message->getBody(),
                                'routing_key' => $message->getRoutingKey(),
                                'retry_count' => $retryCount
                            ]);
                            
                            // Подтверждаем получение сообщения, чтобы оно попало в мертвую очередь
                            $message->reject(false);
                            return;
                        }
                        
                        // Вызываем пользовательский обработчик
                        $result = $callback($data, $message);
                        
                        // Если обработчик вернул true, подтверждаем получение сообщения
                        if ($result) {
                            $message->ack();
                            
                            // ✅ ЛОГИРОВАНИЕ УСПЕШНОЙ ОБРАБОТКИ
                            if (config('rabbitmq-events.logging.log_successful_ack', true)) {
                                Log::info('✅ Сообщение успешно обработано и подтверждено (ACK)', [
                                    'routing_key' => $message->getRoutingKey(),
                                    'delivery_tag' => $message->getDeliveryTag(),
                                    'event_id' => $data['event_id'] ?? 'unknown',
                                ]);
                            }
                        } else {
                            // Логическая ошибка обработки — подтверждаем, чтобы не зациклиться
                            $message->ack();

                            Log::warning("⚠️ Обработчик вернул false, сообщение подтверждено без повторной обработки (ACK)", [
                                'routing_key' => $message->getRoutingKey(),
                                'retry_count' => $retryCount + 1,
                                'event_id' => $data['event_id'] ?? 'unknown',
                            ]);
                        }
                    } catch (Exception $e) {
                        Log::error("❌ Ошибка при обработке сообщения: {$e->getMessage()}", [
                            'exception' => $e,
                            'message' => $message->getBody(),
                            'routing_key' => $message->getRoutingKey(),
                            'event_id' => isset($data) ? ($data['event_id'] ?? 'unknown') : 'unknown',
                            'trace' => $e->getTraceAsString(),
                        ]);
                        
                        // В случае исключения — отправляем в DLX/ретрай (без немедленного ре-доставления)
                        $message->nack(false);
                        
                        Log::warning("🔄 Сообщение отправлено на повторную обработку (NACK)", [
                            'routing_key' => $message->getRoutingKey(),
                            'delivery_tag' => $message->getDeliveryTag(),
                        ]);
                    }
                }
            );
            
            Log::info("Начато прослушивание очереди: {$queue}");
            
            // Запускаем цикл ожидания сообщений
            while ($this->channel->is_consuming()) {
                $this->channel->wait();
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error("Ошибка при прослушивании очереди: {$e->getMessage()}", [
                'exception' => $e,
                'entity_type' => $entityType,
            ]);
            
            return false;
        }
    }
}
