<?php

namespace App\RabbitMQ\Consumers;

use App\RabbitMQ\DTO\OrderEventDTO;
use App\Services\OrderImportService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class OrderConsumer
{
    public function __construct(
        private readonly OrderImportService $importService,
        private readonly string $logChannel = 'rabbitmq-orders'
    ) {}

    public function handle(AMQPMessage $message): bool
    {
        $body = $message->getBody();
        $deliveryTag = $message->getDeliveryTag();
        $routingKey = $message->getRoutingKey();

        Log::channel($this->logChannel)->debug("Получено сообщение", [
            'delivery_tag' => $deliveryTag,
            'routing_key' => $routingKey,
            'body_length' => strlen($body),
        ]);

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $event = $data['event'] ?? $routingKey;

            // order.updated — обновление статуса существующего заказа
            if ($event === 'order.updated') {
                return $this->handleOrderUpdated($data);
            }

            // order.created — создание нового заказа (текущая логика)
            $dto = OrderEventDTO::fromMessage($data);

            if ($dto->status !== 'assembled') {
                Log::channel($this->logChannel)->info("Заказ пропущен: статус не assembled", [
                    'shop_order_id' => $dto->id,
                    'status' => $dto->status,
                ]);
                return true;
            }

            if ($this->importService->isDuplicate($dto->id)) {
                Log::channel($this->logChannel)->info("Дубликат заказа, ACK", [
                    'shop_order_id' => $dto->id,
                ]);
                return true;
            }

            $this->importService->import($dto);

            return true;

        } catch (InvalidArgumentException $e) {
            Log::channel($this->logChannel)->error("Ошибка валидации", [
                'error' => $e->getMessage(),
                'body' => mb_substr($body, 0, 500),
            ]);
            return false;

        } catch (Throwable $e) {
            Log::channel($this->logChannel)->error("Ошибка обработки заказа", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    protected function handleOrderUpdated(array $data): bool
    {
        $payload = $data['payload'] ?? $data;
        $shopOrderId = (int) ($payload['id'] ?? 0);
        $status = $payload['status'] ?? null;
        $items = $payload['items'] ?? [];

        if (!$shopOrderId) {
            Log::channel($this->logChannel)->warning("order.updated: отсутствует id", [
                'data' => array_keys($payload),
            ]);
            return true;
        }

        // Сначала синхронизируем актуальный состав собранного заказа (замены,
        // недовложения, фактический вес) — он уедет в 1С при следующем обмене.
        if (!empty($items) && is_array($items)) {
            $this->importService->syncItemsByShopOrderId($shopOrderId, $items);
        }

        if ($status) {
            $this->importService->updateStatus($shopOrderId, $status);
        }

        return true;
    }

    public function failed(AMQPMessage $message, Throwable $exception): void
    {
        Log::channel($this->logChannel)->critical("Сообщение отправлено в DLQ", [
            'error' => $exception->getMessage(),
            'body' => mb_substr($message->getBody(), 0, 500),
        ]);
    }
}
