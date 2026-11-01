<?php

namespace App\Console\Commands\RabbitMQ;

use App\Services\EventHandlers\OrderEventHandler;
use App\Services\RabbitMQ\Consumer;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Обрабатывает события заказов из RabbitMQ';

    /**
     * @var Consumer
     */
    protected $consumer;

    /**
     * @var OrderEventHandler
     */
    protected $eventHandler;

    /**
     * @param Consumer $consumer
     * @param OrderEventHandler $eventHandler
     */
    public function __construct(Consumer $consumer, OrderEventHandler $eventHandler)
    {
        parent::__construct();

        $this->consumer = $consumer;
        $this->eventHandler = $eventHandler;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Запуск обработчика событий заказов...');

        // Настраиваем очередь
        $this->consumer->setup('orders');

        // Начинаем прослушивание очереди
        $this->consumer->consume('orders', function (array $data, AMQPMessage $message) {
            $this->info("Получено событие заказа: {$message->getRoutingKey()}");
            
            // Обрабатываем событие с помощью обработчика
            $result = $this->eventHandler->handle($data, $message);
            
            if ($result) {
                $this->info("Событие заказа обработано успешно: {$message->getRoutingKey()}");
            } else {
                $this->error("Ошибка при обработке события заказа: {$message->getRoutingKey()}");
            }
            
            return $result;
        });

        return Command::SUCCESS;
    }
}
