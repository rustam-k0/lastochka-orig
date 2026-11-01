<?php

namespace App\Console\Commands\RabbitMQ;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Illuminate\Support\Facades\Log;

class ProcessDeadLetterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:process-dead-letters {entity_type} {--retry : Переместить сообщения обратно в основную очередь}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Обрабатывает сообщения из мертвой очереди';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $entityType = $this->argument('entity_type');
        $shouldRetry = $this->option('retry');
        
        $config = config('rabbitmq.queues.' . $entityType);
        
        if (!$config) {
            $this->error("Неизвестный тип сущности: {$entityType}");
            return Command::FAILURE;
        }
        
        $queue = $config['queue'];
        $deadLetterQueue = $queue . '.dead';
        
        $this->info("Обработка мертвой очереди: {$deadLetterQueue}");
        
        // Создаем соединение с RabbitMQ
        $connection = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password'),
            config('rabbitmq.vhost')
        );
        
        $channel = $connection->channel();
        
        // Проверяем, существует ли очередь
        try {
            $queueInfo = $channel->queue_declare($deadLetterQueue, true, true, false, false);
            $messageCount = $queueInfo[1];
            
            $this->info("Количество сообщений в мертвой очереди: {$messageCount}");
            
            if ($messageCount === 0) {
                $this->info("Мертвая очередь пуста.");
                $connection->close();
                return Command::SUCCESS;
            }
            
            if ($shouldRetry) {
                $this->processForRetry($channel, $deadLetterQueue, $config['exchange']);
            } else {
                $this->processForInspection($channel, $deadLetterQueue);
            }
            
            $connection->close();
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка при обработке мертвой очереди: {$e->getMessage()}");
            Log::error("Ошибка при обработке мертвой очереди: {$e->getMessage()}", [
                'exception' => $e,
                'entity_type' => $entityType,
            ]);
            
            $connection->close();
            return Command::FAILURE;
        }
    }
    
    /**
     * Обрабатывает сообщения из мертвой очереди для повторной попытки.
     *
     * @param \PhpAmqpLib\Channel\AMQPChannel $channel
     * @param string $deadLetterQueue
     * @param string $exchange
     * @return void
     */
    protected function processForRetry($channel, $deadLetterQueue, $exchange)
    {
        $this->info("Перемещение сообщений обратно в основную очередь...");
        $count = 0;
        
        // Получаем сообщения из мертвой очереди и перемещаем их обратно в основную очередь
        $channel->basic_consume(
            $deadLetterQueue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($channel, $exchange, &$count) {
                $routingKey = $message->getRoutingKey();
                
                // Если routing key пустой, пытаемся восстановить его из заголовков
                if (empty($routingKey)) {
                    $headers = $message->get_properties();
                    if (isset($headers['application_headers']) && $headers['application_headers'] instanceof AMQPTable) {
                        $appHeaders = $headers['application_headers']->getNativeData();
                        if (isset($appHeaders['x-death']) && is_array($appHeaders['x-death']) && !empty($appHeaders['x-death'])) {
                            $routingKey = $appHeaders['x-death'][0]['routing-keys'][0] ?? '';
                        }
                    }
                }
                
                if (empty($routingKey)) {
                    $this->warn("Не удалось определить routing key для сообщения. Использую default.");
                    $routingKey = 'default';
                }
                
                // Создаем новое сообщение с тем же содержимым
                $newMessage = new AMQPMessage(
                    $message->getBody(),
                    [
                        'content_type' => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    ]
                );
                
                // Публикуем сообщение обратно в основную очередь
                $channel->basic_publish($newMessage, $exchange, $routingKey);
                
                // Подтверждаем получение сообщения из мертвой очереди
                $message->ack();
                
                $count++;
                if ($count % 10 === 0) {
                    $this->info("Перемещено сообщений: {$count}");
                }
            }
        );
        
        // Ждем, пока все сообщения будут обработаны
        while ($channel->is_consuming()) {
            $channel->wait(null, false, 1);
        }
        
        $this->info("Всего перемещено сообщений: {$count}");
    }
    
    /**
     * Обрабатывает сообщения из мертвой очереди для проверки.
     *
     * @param \PhpAmqpLib\Channel\AMQPChannel $channel
     * @param string $deadLetterQueue
     * @return void
     */
    protected function processForInspection($channel, $deadLetterQueue)
    {
        $this->info("Проверка сообщений в мертвой очереди...");
        $count = 0;
        
        // Получаем сообщения из мертвой очереди для проверки
        $channel->basic_consume(
            $deadLetterQueue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use (&$count) {
                $body = $message->getBody();
                $routingKey = $message->getRoutingKey();
                
                // Выводим информацию о сообщении
                $this->line("------ Сообщение #{$count} ------");
                $this->line("Routing Key: {$routingKey}");
                
                // Пытаемся декодировать JSON
                $data = json_decode($body, true);
                if ($data !== null) {
                    $this->line("Содержимое: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    $this->line("Содержимое (не JSON): " . substr($body, 0, 100) . (strlen($body) > 100 ? '...' : ''));
                }
                
                // Получаем информацию о причине попадания в мертвую очередь
                $headers = $message->get_properties();
                if (isset($headers['application_headers']) && $headers['application_headers'] instanceof AMQPTable) {
                    $appHeaders = $headers['application_headers']->getNativeData();
                    if (isset($appHeaders['x-death'])) {
                        $this->line("Причина: " . json_encode($appHeaders['x-death'], JSON_PRETTY_PRINT));
                    }
                }
                
                $this->line("---------------------------");
                
                // Подтверждаем получение сообщения, но оставляем его в очереди
                $message->nack(true);
                
                $count++;
                if ($count >= 10) {
                    // Останавливаем потребление после 10 сообщений
                    $channel->basic_cancel($message->getConsumerTag());
                }
            }
        );
        
        // Ждем, пока не будет обработано 10 сообщений или все сообщения
        while ($channel->is_consuming() && $count < 10) {
            $channel->wait(null, false, 1);
        }
        
        $this->info("Проверено сообщений: {$count}");
        $this->info("Для повторной обработки всех сообщений используйте опцию --retry");
    }
}
