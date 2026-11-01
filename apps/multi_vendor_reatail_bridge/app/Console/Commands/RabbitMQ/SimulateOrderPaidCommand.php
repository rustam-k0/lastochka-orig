<?php

namespace App\Console\Commands\RabbitMQ;

use App\Services\RabbitMQ\Publisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Mapping\OrderMapping;

class SimulateOrderPaidCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:simulate-order-paid {order_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Имитирует событие оплаты заказа в RabbitMQ';

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

        $this->info("Имитация события оплаты заказа: paid для заказа #{$orderId}");

        // Находим заказ в маппинге
        $orderData = OrderMapping::where('id', $orderId)->first();

        if (!$orderData) {
            $this->error("Заказ с ID {$orderId} не найден");
            return Command::FAILURE;
        }

        // Создаем данные для события оплаты
        $data = [
            'id' => $orderData->id,
            'is_paid' => true
        ];

        try {
            // Публикуем событие в RabbitMQ
            $this->publisher->publish(
                'orders',
                'order.paid',
                $data
            );

            $this->info("Событие order.paid успешно опубликовано");
            Log::info("Событие order.paid успешно опубликовано", [
                'order_id' => $orderId
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка при публикации события: {$e->getMessage()}");
            Log::error("Ошибка при публикации события order.paid", [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'exception' => $e
            ]);

            return Command::FAILURE;
        }
    }
}
