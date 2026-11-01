<?php

namespace App\Console\Commands\RabbitMQ;

use App\Services\RabbitMQ\Publisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Mapping\OrderMapping;

class SimulateOrderStatusChangeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:simulate-status-change {order_id} {status=assembling-complete}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Имитирует событие изменения статуса заказа в RabbitMQ';

    /**
     * @var Publisher
     */
    protected $publisher;

    /**
     * @param Publisher $publisher
     */
    public function __construct(Publisher $publisher)
    {
        parent::__construct();

        $this->publisher = $publisher;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $orderId = $this->argument('order_id');
        $status = $this->argument('status');

        $this->info("Имитация события изменения статуса заказа: status.changed для заказа #{$orderId} на статус {$status}");

        // Создаем тестовые данные заказа
        $orderData = OrderMapping::where('id', $orderId)->first();

        if (!$orderData) {
            $this->error("Заказ с ID {$orderId} не найден");
            return Command::FAILURE;
        }

        $data = [
            'id' => $orderData->id,
            'status' => $status,
        ];

        try {
            // Публикуем событие в RabbitMQ
            $this->publisher->publish(
                'orders',
                'order.status.changed',
                $data
            );

            $this->info("Событие status.changed успешно опубликовано");
            Log::info("Событие status.changed успешно опубликовано", [
                'order_id' => $orderId,
                'status' => $status
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка при публикации события: {$e->getMessage()}");
            Log::error("Ошибка при публикации события status.changed", [
                'order_id' => $orderId,
                'status' => $status,
                'error' => $e->getMessage(),
                'exception' => $e
            ]);

            return Command::FAILURE;
        }
    }
}
