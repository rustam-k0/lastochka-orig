<?php

namespace App\Console\Commands\RabbitMQ;

use App\Services\RabbitMQ\Publisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SimulateOrderEventCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:simulate-order-event {event_type=created} {order_id=12345}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Имитирует событие заказа в RabbitMQ';

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
        $eventType = $this->argument('event_type');
        $orderId = $this->argument('order_id');

        $this->info("Имитация события заказа: order.{$eventType} для заказа #{$orderId}");

        // Создаем тестовые данные заказа
        $orderData = $this->generateOrderData($orderId);

        try {
            // Публикуем событие в RabbitMQ
            // Оборачиваем данные в структуру, которую ожидает обработчик
            $this->publisher->publish(
                'orders',
                "order.{$eventType}",
                $orderData
            );

            $this->info("Событие order.{$eventType} успешно опубликовано");
            Log::info("Событие order.{$eventType} успешно опубликовано", [
                'order_id' => $orderId,
                'event_type' => $eventType
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка при публикации события: {$e->getMessage()}");
            Log::error("Ошибка при публикации события order.{$eventType}", [
                'order_id' => $orderId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'exception' => $e
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Генерирует тестовые данные заказа согласно спецификации.
     *
     * @param string $orderId
     * @return array
     */
    private function generateOrderData(string $orderId): array
    {
        return [
            'items' => [
                [
                    'id' => 76,
                    'source' => 'bagisto',
                    'quantity' => 3,
                    'created_at' => '2025-11-17 10:56:30.000000',
                    'externalId' => null,
                    'line_total' => 3600,
                    'product_id' => '12345',
                    'unit_price' => 1200,
                    'product_name' => 'ТОвар',
                    'line_subtotal' => 3600,
                    'product_source' => 'bagisto',
                    'line_discount_total' => 0,
                    'unit_price_effective' => 1200,
                ],
                [
                    'id' => 77,
                    'source' => 'bagisto',
                    'quantity' => 3,
                    'created_at' => '2025-11-17 13:55:22.000000',
                    'externalId' => null,
                    'line_total' => 1800,
                    'product_id' => '456',
                    'unit_price' => 600,
                    'product_name' => 'Товар 2',
                    'line_subtotal' => 1800,
                    'product_source' => 'bagisto',
                    'line_discount_total' => 0,
                    'unit_price_effective' => 600,
                ],
            ],
            'source' => 'bagisto',
            'status' => 'new',
            'address' => [
                'flat' => '1',
                'floor' => 14,
                'street' => 'Ленина',
                'city' => 'Ижевск',
                'region' => 'Удмуртская Республика',
                'address' => 'улица Ленина, д. 100, стр. 12, корп. 15, кв./офис 1, под. 13, эт. 14, Коммент',
                'entrance' => 13,
            ],
            'is_paid' => true,
            'shop_code' => 'comradesapps',
            'created_at' => '2025-11-17 10:56:30.000000',
            'externalId' => $orderId,
            'client_info' => [
                'name' => 'Иван',
                'email' => 'evgr89@yandex.ru',
                'phone' => '89229734344',
                'surname' => 'Иванов',
                'patronymic' => '',
            ],
            'grand_total' => 5700,
            'slot_end_date' => '2025-11-18 13:00:00',
            'discount_total' => 0,
            'items_subtotal' => 5400,
            'shipping_total' => 300,
            'shipping_method' => 'courier',
            'slot_start_date' => '2025-11-18 09:00:00',
            'items_total_after_discount' => 5400,
        ];
    }
}
