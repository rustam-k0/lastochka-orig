<?php

namespace App\Console\Commands\RabbitMQ;

use App\Services\RabbitMQ\Consumer;
use App\Services\Consumers\ProductConsumerHandler;
use App\Services\Consumers\CategoryConsumerHandler;
use App\Services\Consumers\CustomerConsumerHandler;
use App\Services\Consumers\OrderConsumerHandler;
use App\Services\Consumers\UserConsumerHandler;
use App\Services\Consumers\DeliveryAddressConsumerHandler;
use App\Services\Consumers\ShopConsumerHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Команда для запуска Consumer-а RabbitMQ для определенной сущности.
 *
 * Использование:
 * php artisan rabbitmq:consume {entity}
 *
 * Где {entity} может быть: product, category, customer, order, user
 */
class ConsumeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume {entity : Тип сущности (product, category, customer, order, user)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Запускает Consumer для обработки событий из RabbitMQ';

    /**
     * Маппинг сущностей на handler-ы.
     */
    protected array $handlers = [
        'product' => ProductConsumerHandler::class,
        'category' => CategoryConsumerHandler::class,
        'customer' => CustomerConsumerHandler::class,
        'order' => OrderConsumerHandler::class,
        'user' => UserConsumerHandler::class,
        'delivery_address' => DeliveryAddressConsumerHandler::class,
        'shop' => ShopConsumerHandler::class,
    ];

    /**
     * Маппинг единственного числа во множественное для конфига.
     */
    protected array $entityPluralMap = [
        'product' => 'products',
        'category' => 'categories',
        'customer' => 'customers',
        'order' => 'orders',
        'user' => 'users',
        'delivery_address' => 'delivery_addresses',
        'shop' => 'shops',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $entity = $this->argument('entity');

        // Валидация типа сущности
        if (!isset($this->handlers[$entity])) {
            $this->error("Неизвестный тип сущности: {$entity}");
            $this->info("Доступные типы: " . implode(', ', array_keys($this->handlers)));
            return 1;
        }

        $this->info("Запуск Consumer для сущности: {$entity}");

        try {
            // Получаем конфигурацию RabbitMQ
            $config = config('rabbitmq');
            
            // Получаем форму множественного числа для конфига
            $entityPlural = $this->entityPluralMap[$entity] ?? $entity;
            
            // Проверяем наличие конфигурации для сущности
            if (!isset($config['queues'][$entityPlural])) {
                $this->error("Конфигурация для {$entity} ({$entityPlural}) не найдена в config/rabbitmq.php");
                return 1;
            }

            // Создаем подключение к RabbitMQ
            $connection = new AMQPStreamConnection(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password'],
                $config['vhost']
            );

            $channel = $connection->channel();

            // Подготавливаем конфиг для Consumer (маппим на единственное число)
            $consumerConfig = [
                $entity => $config['queues'][$entityPlural]
            ];

            // Создаем Consumer
            $consumer = new Consumer($channel, $consumerConfig);

            // Настраиваем очереди
            if (!$consumer->setup($entity)) {
                $this->error("Не удалось настроить очереди для {$entity}");
                return 1;
            }

            // Создаем handler
            $handlerClass = $this->handlers[$entity];
            $handler = app($handlerClass);

            $this->info("Consumer запущен. Ожидание сообщений для {$entity}...");
            $this->info("Для остановки нажмите Ctrl+C");

            // Запускаем прослушивание с обработчиком
            $consumer->consume($entity, function ($data, $message) use ($handler, $entity) {
                $eventId = $data['event_id'] ?? 'unknown';
                $timestamp = date('Y-m-d H:i:s');
                
                $this->info("[{$timestamp}] ⏳ Processing {$entity} event: {$eventId}");
                
                $result = $handler->handle($data, $message);
                
                $timestamp = date('Y-m-d H:i:s');
                if ($result) {
                    $this->info("[{$timestamp}] ✅ Processed {$entity} event: {$eventId}");
                } else {
                    $this->warn("[{$timestamp}] ⚠️ Failed to process {$entity} event: {$eventId}");
                }
                
                return $result;
            });

            // Закрываем соединение при завершении
            $channel->close();
            $connection->close();

            return 0;

        } catch (\Exception $e) {
            $this->error("Ошибка при запуске Consumer: {$e->getMessage()}");
            Log::error("Ошибка Consumer команды: {$e->getMessage()}", [
                'exception' => $e,
                'entity' => $entity,
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }
}
