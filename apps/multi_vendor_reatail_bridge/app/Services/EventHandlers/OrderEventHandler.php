<?php

namespace App\Services\EventHandlers;

use App\Models\Mapping\OrderMapping;
use App\Services\Converters\OrderConverter;
use App\Services\RetailCRM\RetailCRMService;
use App\Services\RetailCRM\RetailCRMApiService;
use App\Services\RabbitMQ\Publisher;
use App\Services\Idempotency\IdempotencyService;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Cache;

class OrderEventHandler extends BaseEventHandler
{
    /**
     * @var OrderConverter
     */
    protected $converter;

    /**
     * @var RetailCRMService
     */
    protected $retailCRM;

    /**
     * @var RetailCRMApiService
     */
    protected $retailCRMAPIService;

    /**
     * @var IdempotencyService
     */
    protected $idempotency;

    /**
     * @param Publisher $publisher
     * @param OrderConverter $converter
     * @param RetailCRMService $retailCRM
     * @param RetailCRMApiService $retailCRMAPIService
     * @param IdempotencyService $idempotency
     */
    public function __construct(
        Publisher           $publisher,
        OrderConverter      $converter,
        RetailCRMService    $retailCRM,
        RetailCRMApiService $retailCRMAPIService,
        IdempotencyService  $idempotency
    ) {
        parent::__construct($publisher);

        $this->converter = $converter;
        $this->retailCRM = $retailCRM;
        $this->retailCRMAPIService = $retailCRMAPIService;
        $this->idempotency = $idempotency;
    }

    /**
     * Обрабатывает событие заказа из RabbitMQ.
     *
     * @param array $data Данные события
     * @param AMQPMessage $message Сообщение из RabbitMQ
     */
    public function handle(array $data, AMQPMessage $message)
    {
        \Log::info("Обработка заказа", [
            'data' => $data
        ]);

        // Проверяем структуру данных
        // Если данные обернуты в ключ 'data', извлекаем их
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        // Если в данных нет id, но есть externalId, используем его как id
        if (!isset($data['id']) && isset($data['externalId'])) {
            $data['id'] = $data['externalId'];
            $this->logInfo("Использован externalId в качестве id", [
                'externalId' => $data['externalId']
            ]);
        }

        // Если источник данных - RetailCRM, то пропускаем обработку,
        // чтобы избежать циклических обновлений
        $retailcrmSource = config('retailcrm.source', 'retailcrm');
        if (isset($data['source']) && $data['source'] === $retailcrmSource) {
            $this->logInfo("Пропуск события от RetailCRM", [
                'order_id' => $data['id'] ?? 'неизвестно',
                'source' => $data['source'],
            ]);

            return true;
        }

        // Получаем тип события из раутинг ключа
        $eventType = $this->getEventType($message);

        $this->logInfo("Событие заказа", [
            'event' => $eventType,
        ]);

        try {
            switch ($eventType) {
                case 'created':
                    return $this->handleCreate($data);

                case 'updated':
                    return $this->handleUpdate($data);

                case 'status':
                case 'status.changed':
                case 'status_changed':
                    return $this->handleStatusChange($data);

                case 'cancelled':
                    return $this->handleCancel($data);

                case 'paid':
                    return $this->handlePaid($data);

                case 'refunded':
                    return $this->handleRefund($data);

                case 'delivery':
                case 'delivery.updated':
                    return $this->handleDeliveryUpdate($data);

                default:
                    $this->logError("Неизвестный тип события заказа: {$eventType}");
                    return false;
            }
        } catch (\Exception $e) {
            $this->logError("Ошибка при обработке события заказа: {$e->getMessage()}", [
                'exception' => $e,
                'order_id' => $data['id'],
                'event_type' => $eventType,
            ]);

            return false;
        }
    }

    /**
     * Обрабатывает создание заказа.
     *
     * @param array $data
     * @return bool
     */
    protected function handleCreate(array $data)
    {
        try {
            $this->logInfo("Обработка создания заказа", [
                'order_id' => $data['id'] ?? 'неизвестно',
                'data' => $data
            ]);

            // Проверяем наличие обязательных полей
            if (!isset($data['id'])) {
                $this->logError("Отсутствует обязательное поле 'id' в данных заказа");
                return false;
            }

            $source = $data['source'] ?? 'unknown';
            $externalId = (string)($data['externalId'] ?? $data['id']);
            $shopCode = $data['shop_code'] ?? null;
            $eventId = (string)($data['event_id'] ?? ($externalId . '.created'));
            $timestamp = (int)($data['timestamp'] ?? time());

            $retailcrmData = $this->converter->toRetailCRM($data);
            $this->logInfo("Данные заказа после конвертации", [
                'retailcrm_data' => $retailcrmData
            ]);

            return $this->idempotency->handle('order', $source, $externalId, $shopCode, $eventId, $timestamp, function () use ($data, $retailcrmData) {
                $result = $this->retailCRM->createOrder($retailcrmData);

                if ($result !== false) {
                    $retailCRMOrderId = $result->id ?? null;

                    DB::transaction(function () use ($data, $retailCRMOrderId) {
                        OrderMapping::updateOrCreate(
                            ['id' => $data['id']],
                            [
                                'source' => $data['source'] ?? null,
                                'external_id' => $retailCRMOrderId,
                                'data' => $data
                            ]
                        );
                    });

                    return true;
                }

                return false;
            });
        } catch (\Exception $e) {
            $this->logError("Ошибка при создании заказа: {$e->getMessage()}", [
                'exception' => $e,
                'order_id' => $data['id'] ?? 'неизвестно',
            ]);
            return false;
        }
    }

    /**
     * Обрабатывает обновление заказа.
     *
     * @param array $data
     * @return bool
     */
    protected function handleUpdate(array $data)
    {
        // Находим маппинг для заказа
        $mapping = OrderMapping::where('id', $data['id'])->first();

        // Если маппинг не найден по id, пробуем найти по externalId
        if (!$mapping && !empty($data['externalId'])) {
            $mapping = OrderMapping::where('id', $data['externalId'])->first();
            if ($mapping) {
                $this->logInfo("Маппинг найден по externalId", [
                    'original_id' => $data['id'],
                    'externalId' => $data['externalId'],
                    'mapping_id' => $mapping->id,
                ]);
            }
        }

        if (!$mapping) {
            $this->logError("Не найден маппинг для обновляемого заказа, пропуск обработки", [
                'order_id' => $data['id'],
                'externalId' => $data['externalId'] ?? null,
            ]);

            return false;
        }

        $retailcrmData = $this->converter->toRetailCRM($data);
        if (!empty($mapping->external_id)) {
            $retailcrmData['externalId'] = $mapping->external_id;
        }
        
        // Удаляем customer из данных при обновлении, чтобы избежать дублирования клиентов.
        // RetailCRM при получении customer без id может создать нового клиента.
        // Клиент уже привязан к заказу при его создании.
        unset($retailcrmData['customer']);

        $this->logInfo("Данные заказа после конвертации для обновления", [
            'retailcrm_data' => $retailcrmData
        ]);

        $source = $data['source'] ?? 'unknown';
        $externalId = (string)($data['externalId'] ?? $data['id']);
        $shopCode = $data['shop_code'] ?? null;
        $eventId = (string)($data['event_id'] ?? ($externalId . '.updated'));
        $timestamp = (int)($data['timestamp'] ?? time());

        return $this->idempotency->handle('order', $source, $externalId, $shopCode, $eventId, $timestamp, function () use ($data, $retailcrmData, $mapping) {
            $result = $this->retailCRM->updateOrder($retailcrmData);

            if ($result) {
                OrderMapping::updateOrCreate(
                    ['id' => $data['id']],
                    [
                        'external_id' => $mapping->external_id,
                        'data' => $data
                    ]
                );
            }

            return (bool) $result;
        });
    }

    /**
     * Обрабатывает изменение статуса заказа.
     *
     * @param array $data
     * @return bool
     */
    protected function handleStatusChange(array $data)
    {
        // Находим маппинг для заказа
        $mapping = OrderMapping::where('id', $data['id'])->first();

        if (!$mapping) {
            $this->logError("Не найден маппинг для заказа с изменением статуса", [
                'order_id' => $data['id'],
            ]);

            return false;
        }

        // Берём статус: приоритет у new_status, fallback на status
        $systemStatus = $data['new_status'] ?? $data['status'] ?? null;

        if (!$systemStatus) {
            $this->logError("Отсутствует статус в событии status_changed", [
                'order_id' => $data['id'],
                'data' => $data,
            ]);

            return false;
        }

        // Маппим системный статус в статус RetailCRM
        $statusMap = config('retailcrm.order_statuses', []);
        $retailCrmStatus = $statusMap[$systemStatus] ?? null;

        if (!$retailCrmStatus) {
            $this->logError("Неизвестный системный статус, маппинг не найден", [
                'order_id' => $data['id'],
                'system_status' => $systemStatus,
                'available_statuses' => array_keys($statusMap),
            ]);

            return false;
        }

        $this->logInfo("Обновление статуса заказа в RetailCRM", [
            'order_id' => $data['id'],
            'old_status' => $data['old_status'] ?? 'unknown',
            'new_status' => $systemStatus,
            'retailcrm_status' => $retailCrmStatus,
        ]);

        $source = $data['source'] ?? 'unknown';
        $externalId = (string)($data['externalId'] ?? $data['id']);
        $shopCode = $data['shop_code'] ?? null;
        $eventId = (string)($data['event_id'] ?? ($externalId . '.status.changed'));
        $timestamp = (int)($data['timestamp'] ?? time());

        return $this->idempotency->handle('order', $source, $externalId, $shopCode, $eventId, $timestamp, function () use ($data, $mapping, $retailCrmStatus) {
            $result = $this->retailCRM->updateOrderStatus($mapping->external_id, $retailCrmStatus);

            if ($result) {
                $mapping->data = array_merge($mapping->data ?? [], ['status' => $retailCrmStatus]);
                $mapping->save();
            }

            return (bool) $result;
        });
    }

    /**
     * Обрабатывает отмену заказа.
     *
     * @param array $data
     * @return bool
     */
    protected function handleCancel(array $data)
    {
        // Устанавливаем статус "отменен"
        $data['status'] = 'cancel-other';

        return $this->handleStatusChange($data);
    }

    /**
     * Обрабатывает оплату заказа.
     *
     * @param array $data
     * @return bool
     */
    protected function handlePaid(array $data)
    {
        // Находим маппинг для заказа
        $mapping = OrderMapping::where('id', $data['id'])->first();

        if (!$mapping) {
            $this->logError("Не найден маппинг для оплаченного заказа", [
                'order_id' => $data['id'],
            ]);

            return false;
        }
        $retailcrmData = $this->converter->toRetailCRM(array_merge($data, [
            'is_paid' => true,
        ]));
        if (!empty($mapping->external_id)) {
            $retailcrmData['externalId'] = $mapping->external_id;
        }
        
        // Удаляем customer из данных при обновлении оплаты, чтобы избежать дублирования клиентов
        unset($retailcrmData['customer']);
        $this->logInfo("Данные заказа после конвертации для оплаты", [
            'retailcrm_data' => $retailcrmData
        ]);

        $source = $data['source'] ?? 'unknown';
        $externalId = (string)($data['externalId'] ?? $data['id']);
        $shopCode = $data['shop_code'] ?? null;
        $eventId = (string)($data['event_id'] ?? ($externalId . '.paid'));
        $timestamp = (int)($data['timestamp'] ?? time());

        return $this->idempotency->handle('order', $source, $externalId, $shopCode, $eventId, $timestamp, function () use ($data, $retailcrmData, $mapping) {
            $result = $this->retailCRM->updateOrder($retailcrmData);

            if ($result) {
                $mapping->data = array_merge($mapping->data ?? [], ['is_paid' => true]);
                $mapping->save();
            }

            return (bool) $result;
        });
    }

    /**
     * Обрабатывает возврат по заказу.
     *
     * @param array $data
     * @return bool
     */
    protected function handleRefund(array $data)
    {
        // Устанавливаем статус "возвращен"
        $data['status'] = 'returned';

        return $this->handleStatusChange($data);
    }

    /**
     * Обрабатывает обновление данных доставки.
     *
     * @param array $data
     * @return bool
     */
    protected function handleDeliveryUpdate(array $data)
    {
        // Находим маппинг для заказа
        $mapping = OrderMapping::where('id', $data['id'])->first();

        if (!$mapping) {
            $this->logError("Не найден маппинг для заказа с обновлением доставки", [
                'order_id' => $data['id'],
            ]);

            return false;
        }

        $retailcrmData = $this->converter->toRetailCRM($data);
        if (!empty($mapping->external_id)) {
            $retailcrmData['externalId'] = $mapping->external_id;
        }
        
        // Удаляем customer из данных при обновлении доставки, чтобы избежать дублирования клиентов
        unset($retailcrmData['customer']);

        $this->logInfo("Данные заказа после конвертации для обновления доставки", [
            'retailcrm_data' => $retailcrmData
        ]);

        $source = $data['source'] ?? 'unknown';
        $externalId = (string)($data['externalId'] ?? $data['id']);
        $shopCode = $data['shop_code'] ?? null;
        $eventId = (string)($data['event_id'] ?? ($externalId . '.delivery.updated'));
        $timestamp = (int)($data['timestamp'] ?? time());

        return $this->idempotency->handle('order', $source, $externalId, $shopCode, $eventId, $timestamp, function () use ($data, $retailcrmData, $mapping) {
            $result = $this->retailCRM->updateOrder($retailcrmData);

            if ($result) {
                $mapping->data = array_merge($mapping->data ?? [], [
                    'shipping_method' => $data['shipping_method'] ?? ($mapping->data['shipping_method'] ?? null),
                    'tracking_number' => $data['tracking_number'] ?? ($mapping->data['tracking_number'] ?? null),
                    'address' => $data['address'] ?? ($mapping->data['address'] ?? null),
                    'slot_start_date' => $data['slot_start_date'] ?? ($mapping->data['slot_start_date'] ?? null),
                    'slot_end_date' => $data['slot_end_date'] ?? ($mapping->data['slot_end_date'] ?? null),
                ]);
                $mapping->save();
            }

            return (bool) $result;
        });
    }
}
