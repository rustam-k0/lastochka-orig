<?php

namespace App\Http\Controllers\RetailCRM;

use App\Http\Controllers\Controller;
use App\Models\Mapping\OrderMapping;
use App\Models\Mapping\CustomerMapping;
use App\Models\System\EventSyncMetadata;
use App\Services\Converters\OrderConverter;
use App\Services\Converters\CustomerConverter;
use App\Services\RetailCRM\RetailCRMService;
use App\Services\RabbitMQ\Publisher;
use App\Services\Outbox\OutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RetailCrm\Api\Exception\Api\NotFoundException;

class WebhookController extends Controller
{
    /**
     * @var Publisher
     */
    protected $publisher;

    /**
     * @var OrderConverter
     */
    protected $orderConverter;

    /**
     * @var CustomerConverter
     */
    protected $customerConverter;

    /**
     * @var RetailCRMService
     */
    protected $retailcrmService;

    /**
     * @var OutboxService
     */
    protected $outbox;

    /**
     * @param Publisher $publisher
     * @param OrderConverter $orderConverter
     * @param CustomerConverter $customerConverter
     * @param RetailCRMService $retailcrmService
     * @param OutboxService $outbox
     */
    public function __construct(
        Publisher $publisher,
        OrderConverter $orderConverter,
        CustomerConverter $customerConverter,
        RetailCRMService $retailcrmService,
        OutboxService $outbox
    ) {
        $this->publisher = $publisher;
        $this->orderConverter = $orderConverter;
        $this->customerConverter = $customerConverter;
        $this->retailcrmService = $retailcrmService;
        $this->outbox = $outbox;
    }

    /**
     * Обрабатывает удаление заказа.

     *
     * @param string $retailcrmId
     * @return bool
     */
    protected function handleOrderDeletion(string $retailcrmId): bool
    {
        $mapping = OrderMapping::where('external_id', $retailcrmId)->first();

        if (!$mapping) {
            Log::warning("Попытка удаления заказа, но маппинг не найден", ['retailcrm_id' => $retailcrmId]);
            return false;
        }

        // Мы не удаляем маппинг, чтобы сохранить историю и ID, но отправляем событие
        // Или можно пометить как удаленный в маппинге?
        // Пока просто отправляем событие

        // Структура события удаления обычно содержит только ID
        $payload = [
            'id' => $mapping->id,
            'externalId' => $retailcrmId,
            'source' => config('retailcrm.source'),
            'shop_code' => $mapping->data['shop_code'] ?? 'default', // Fallback
            'deleted_at' => now()->toIso8601String(),
        ];

        $this->outbox->dispatch('order', 'order.deleted', $payload, $mapping->id);
        Log::info("Поставлено в outbox событие удаления заказа: order.deleted", ['id' => $mapping->id]);

        return true;
    }

    /**
     * Обрабатывает удаление клиента.
     *
     * @param string $retailcrmId
     * @return bool
     */
    protected function handleCustomerDeletion(string $retailcrmId): bool
    {
        $mapping = CustomerMapping::where('external_id', $retailcrmId)->first();

        if (!$mapping) {
            Log::warning("Попытка удаления клиента, но маппинг не найден", ['retailcrm_id' => $retailcrmId]);
            return false;
        }

        $payload = [
            'id' => $mapping->id,
            'externalId' => $retailcrmId,
            'source' => config('retailcrm.source'),
            'shop_code' => $mapping->data['shop_code'] ?? 'default',
            'deleted_at' => now()->toIso8601String(),
        ];

        $this->outbox->dispatch('customer', 'customer.deleted', $payload, $mapping->id);
        Log::info("Поставлено в outbox событие удаления клиента: customer.deleted", ['id' => $mapping->id]);

        return true;
    }

    /**
     * Проверяет, является ли событие результатом нашей собственной синхронизации
     *
     * @param array $retailcrmOrder Данные заказа из RetailCRM
     * @param string $eventType Тип события (create, update, status_change и т.д.)
     * @return bool True, если событие нужно пропустить
     */
    protected function shouldSkipEvent(array $retailcrmOrder, string $eventType): bool
    {
        // Проверяем по externalId и по id
        $externalId = $retailcrmOrder['externalId'] ?? null;
        $id = $retailcrmOrder['id'] ?? null;

        if ($externalId) {
            $syncKey = "sync_order_{$externalId}";
            if (Cache::has($syncKey)) {
                Log::info("Пропуск публикации события {$eventType} заказа в RabbitMQ (собственная синхронизация по externalId)", [
                    'order_id' => $id,
                    'external_id' => $externalId
                ]);
                return true;
            }
        }

        if ($id) {
            $syncKey = "sync_order_{$id}";
            if (Cache::has($syncKey)) {
                Log::info("Пропуск публикации события {$eventType} заказа в RabbitMQ (собственная синхронизация по id)", [
                    'order_id' => $id,
                    'external_id' => $externalId
                ]);
                return true;
            }
        }

        return false;
    }

    public function handle(Request $request)
    {
        $data = $request->all();

        \Log::info('Получен вебхук от RetailCRM', [
            'data' => $data,
        ]);

        // Обработка нового формата для клиентов
        if (isset($data['type']) && $data['type'] === 'customer') {
            return $this->processCustomerWebhook($data);
        }

        // return;


        // Проверяем наличие обязательных полей
        if (!isset($data['type']) || !isset($data['order_id'])) {
            Log::warning('Получен вебхук с некорректными данными', [
                'data' => $data,
            ]);

            return response()->json(['success' => false, 'error' => 'Некорректные данные вебхука'], 400);
        }

        // Обрабатываем вебхук в зависимости от типа
        try {
            $type = $data['type'];

            Log::info("Webhook type: {$type}", [
                'type' => $type,
            ]);

            try {
                $response = $this->retailcrmService->getOrder($data['order_id']);

                \Log::info("Заказ успешно получен", [
                    'order' => $response,
                ]);

                // Проверяем, что ответ не false и содержит заказ
                if ($response === false || !isset($response->order)) {
                    \Log::error("Не удалось получить данные заказа из RetailCRM", [
                        'order_id' => $data['order_id'],
                    ]);

                    // Проверяем, является ли это событие результатом нашей синхронизации
                    // Если да, то просто пропускаем его
                    $syncKey = "sync_order_{$data['order_id']}";
                    if (Cache::has($syncKey)) {
                        Log::info("Пропуск обработки вебхука из-за ошибки получения данных (собственная синхронизация)", [
                            'order_id' => $data['order_id'],
                        ]);
                        return response()->json(['success' => true]);
                    }

                    return response()->json(['success' => false, 'error' => 'Не удалось получить данные заказа'], 500);
                }
            } catch (RetailCrmNotFoundException $e) {
                // Обработка удаления заказа
                if ($this->handleOrderDeletion((string)$data['order_id'])) {
                    return response()->json(['success' => true]);
                }
                return response()->json(['success' => false, 'error' => 'Order not found and no mapping exists'], 404);
            } catch (\Exception $e) {
                \Log::error("Ошибка при получении данных заказа из RetailCRM: {$e->getMessage()}", [
                    'exception' => $e,
                    'order_id' => $data['order_id'],
                ]);

                // Проверяем, является ли это событие результатом нашей синхронизации
                $syncKey = "sync_order_{$data['order_id']}";
                if (Cache::has($syncKey)) {
                    Log::info("Пропуск обработки вебхука из-за ошибки получения данных (собственная синхронизация)", [
                        'order_id' => $data['order_id'],
                    ]);
                    return response()->json(['success' => true]);
                }

                return response()->json(['success' => false, 'error' => 'Ошибка при получении данных заказа'], 500);
            }

            $retailcrmOrder = json_decode(json_encode($response->order), true);

            // СНАЧАЛА ищем существующую запись в event_sync_metadata по entity_id (ID из RetailCRM)
            $retailcrmId = $retailcrmOrder['id'];
            $existingMetadata = EventSyncMetadata::where([
                'entity_type' => 'order',
                'entity_id' => (string)$retailcrmId,
                'shop_code' => $retailcrmOrder['site'] ?? config('retailcrm.shop_code', 'default')
            ])->first();

            $order = $this->orderConverter->fromRetailCRM($retailcrmOrder, $existingMetadata);
            // Добавляем raw retailcrm_id для внутреннего использования
            $order['__retailcrm_id'] = $retailcrmOrder['id'];

            \Log::info("Заказ успешно конвертирован", [
                'order' => $order,
            ]);


            switch ($type) {
                // Заказы
                case 'order_create':
                    $this->handleOrderCreate($order);
                    break;

                case 'order_update':
                    $this->handleOrderUpdate($order);
                    break;

                case 'order_status_change':
                    $this->handleOrderStatusChange($order);
                    break;

                case 'order_payment_change':
                    $this->handleOrderPaymentChange($order);
                    break;

                case 'order_delivery_change':
                    $this->handleOrderDeliveryChange($order);
                    break;
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error("Ошибка при обработке вебхука: {$e->getMessage()}", [
                'exception' => $e,
                'type' => $data['type'] ?? null,
                'payload' => $data['payload'] ?? null,
            ]);

            return response()->json(['success' => false, 'error' => 'Внутренняя ошибка сервера'], 500);
        }
    }

    /**
     * Обрабатывает вебхук о создании заказа.
     *
     * @param array $order Сконвертированный заказ
     * @return void
     */
    protected function handleOrderCreate(array $order)
    {
        $retailcrmId = $order['__retailcrm_id'] ?? null;
        unset($order['__retailcrm_id']); // Удаляем служебное поле перед сохранением

        \Log::info("Заказ успешно получен из RetailCRM", [
            'order' => $order,
            'retailcrm_id' => $retailcrmId
        ]);

        if (!$retailcrmId) {
            Log::error("Отсутствует retailcrm_id при создании заказа");
            return;
        }

        // Проверяем ПЕРЕД публикацией, является ли это событие результатом нашей собственной синхронизации
        $shouldSkip = $this->shouldSkipEvent(
            ['id' => $retailcrmId, 'externalId' => $order['externalId'] ?? null],
            'create'
        );

        // Находим или создаем маппинг
        DB::transaction(function () use ($order, $retailcrmId, $shouldSkip) {
            // Проверяем, есть ли уже маппинг по externalId (оригинальный admin ID)
            // чтобы не создавать дубль при получении вебхука для CRM-дубля заказа
            $existingMapping = null;
            if (!empty($order['externalId'])) {
                $existingMapping = OrderMapping::where('id', (string)$order['externalId'])->first();
            }

            if ($existingMapping) {
                // Маппинг уже существует — обновляем external_id на актуальный CRM ID
                Log::info("handleOrderCreate: найден существующий маппинг по externalId, обновляем", [
                    'mapping_id' => $existingMapping->id,
                    'old_external_id' => $existingMapping->external_id,
                    'new_external_id' => (string)$retailcrmId,
                ]);
                $existingMapping->external_id = (string)$retailcrmId;
                $existingMapping->data = $order;
                $existingMapping->save();
                $order['id'] = $existingMapping->id;

                // Публикуем order.updated вместо order.created — это не новый заказ
                if (!$shouldSkip) {
                    $this->outbox->dispatch('order', 'order.updated', $order, $existingMapping->id);
                }
            } else {
                OrderMapping::updateOrCreate(
                    ['external_id' => (string)$retailcrmId],
                    [
                        'id' => $order['id'],
                        'external_id' => (string)$retailcrmId,
                        'source' => config('retailcrm.source'),
                        'data' => $order
                    ]
                );

                // Публикуем событие через outbox только если НЕ нужно пропускать
                if (!$shouldSkip) {
                    $this->outbox->dispatch('order', 'order.created', $order, $order['id']);
                }
            }
        });

        if ($shouldSkip) {
            Log::info("Пропуск публикации order.created (собственная синхронизация)", [
                'order_id' => $order['id'],
                'retailcrm_id' => $retailcrmId
            ]);
            return;
        }

        Log::info("Поставлено в outbox событие создания заказа: order.created", [
            'order_id' => $order['id'],
            'retailcrm_id' => $retailcrmId
        ]);
    }

    /**
     * Сравнивает два массива данных заказа, игнорируя некоторые поля, если необходимо.
     *
     * @param array|null $oldData
     * @param array $newData
     * @return bool
     */
    protected function isSameState(?array $oldData, array $newData): bool
    {
        if (empty($oldData)) {
            return false;
        }

        // Можно исключить поля, которые не должны вызывать событие обновления, если они меняются
        // Например, какие-то внутренние метки. Но пока сравниваем всё.
        // Нужно убедиться, что порядок ключей не влияет, но json_encode в PHP для ассоциативных массивов
        // зависит от порядка. Лучше использовать рекурсивное сравнение массивов (==).

        // Исключим обновленные даты, если логика требует (но обычно смена даты обновления это тоже изменение)
        // unset($oldData['updated_at'], $newData['updated_at']);

        return $oldData == $newData;
    }

    /**
     * Обрабатывает вебхук об обновлении заказа.
     *
     * @param array $order Сконвертированный заказ
     * @return void
     */
    protected function handleOrderUpdate(array $order)
    {
        $retailcrmId = $order['__retailcrm_id'] ?? null;
        unset($order['__retailcrm_id']);

        \Log::info("handleOrderUpdate", [
            'order' => $order,
            'retailcrm_id' => $retailcrmId
        ]);

        if (!$retailcrmId) {
            Log::error("Отсутствует retailcrm_id при обновлении заказа");
            return;
        }

        // Проверяем ПЕРЕД публикацией, является ли это событие результатом нашей собственной синхронизации
        $shouldSkip = $this->shouldSkipEvent(
            ['id' => $retailcrmId, 'externalId' => $order['externalId'] ?? null],
            'update'
        );

        // Находим или создаем маппинг
        DB::transaction(function () use ($order, $retailcrmId, $shouldSkip) {
            // Если order уже имеет правильный source из конвертера, просто ищем/создаем маппинг
            $mapping = OrderMapping::where('external_id', (string)$retailcrmId)->first();

            // Если не нашли по CRM ID, ищем по externalId (оригинальный admin ID)
            if (!$mapping && !empty($order['externalId'])) {
                $existing = OrderMapping::where('id', (string)$order['externalId'])->first();
                if ($existing) {
                    // Это дубль CRM-заказа — обновляем существующий маппинг
                    Log::info("Найден существующий маппинг по externalId, обновляем external_id", [
                        'mapping_id' => $existing->id,
                        'old_external_id' => $existing->external_id,
                        'new_external_id' => (string)$retailcrmId,
                    ]);
                    $existing->external_id = (string)$retailcrmId;
                    $existing->data = $order;
                    $existing->save();
                    $order['id'] = $existing->id;
                    // НЕ публикуем order.created — это не новый заказ, а дубль

                    if (!$shouldSkip) {
                        $this->outbox->dispatch('order', 'order.updated', $order, $existing->id);
                    }
                    return;
                }
            }

            if ($mapping) {
                // Проверка идемпотентности
                if ($this->isSameState($mapping->data, $order)) {
                    Log::info("Пропуск обработки вебхука: данные заказа не изменились", [
                        'order_id' => $order['id'],
                    ]);
                    return;
                }

                $mapping->data = $order;
                $mapping->save();
                // Обновляем ID в объекте, если он вдруг отличается (хотя не должен)
                $order['id'] = $mapping->id;
            } else {
                // Если маппинга нет, создаем новый
                $mapping = OrderMapping::create([
                    'id' => $order['id'],
                    'external_id' => (string)$retailcrmId,
                    'source' => config('retailcrm.source'),
                    'data' => $order,
                ]);
            }

            // Публикуем событие в outbox только если НЕ нужно пропускать
            if (!$shouldSkip) {
                $this->outbox->dispatch('order', 'order.updated', $order, $mapping->id);
            }
        });

        if ($shouldSkip) {
            Log::info("Пропуск публикации order.updated (собственная синхронизация)", [
                'order_id' => $order['id'],
                'retailcrm_id' => $retailcrmId
            ]);
            return;
        }

        Log::info("Поставлено в outbox событие обновления заказа: order.updated", [
            'order_id' => $order['id'],
            'retailcrm_id' => $retailcrmId
        ]);
    }

    /**
     * Обрабатывает вебхук об изменении статуса заказа.
     *
     * @param array $order Сконвертированный заказ
     * @return void
     */
    protected function handleOrderStatusChange(array $order)
    {
        $retailcrmId = $order['__retailcrm_id'] ?? null;
        unset($order['__retailcrm_id']);

        if (!$retailcrmId) {
            Log::warning('handleOrderStatusChange: отсутсвует retailcrm_id');
            return;
        }

        // Проверяем ПЕРЕД публикацией, является ли это событие результатом нашей собственной синхронизации
        $shouldSkip = $this->shouldSkipEvent(
            ['id' => $retailcrmId, 'externalId' => $order['externalId'] ?? null],
            'status_change'
        );

        // Находим маппинг для заказа
        $mapping = OrderMapping::where('external_id', (string)$retailcrmId)->first();

        if (!$mapping && !empty($order['externalId'])) {
            // Пробуем найти маппинг по externalId (оригинальному admin ID)
            $mapping = OrderMapping::where('id', (string)$order['externalId'])->first();
            if ($mapping) {
                Log::info("handleOrderStatusChange: маппинг найден по externalId", [
                    'retailcrm_id' => $retailcrmId,
                    'externalId' => $order['externalId'],
                    'mapping_id' => $mapping->id,
                ]);
                // Обновляем external_id на актуальный CRM ID
                $mapping->external_id = (string)$retailcrmId;
                $mapping->save();
            }
        }

        if (!$mapping) {
            Log::warning('Не найден маппинг для заказа с изменением статуса из RetailCRM', [
                'retailcrm_id' => $retailcrmId,
                'externalId' => $order['externalId'] ?? null,
            ]);
            return;
        }

        // Обновляем маппинг
        DB::transaction(function () use ($mapping, $order, $shouldSkip) {
            $mapping->data = $order;
            $mapping->save();

            // Публикуем событие только если НЕ нужно пропускать
            if (!$shouldSkip) {
                $this->outbox->dispatch('order', 'order.status.changed', $order, $mapping->id);
            }
        });

        if ($shouldSkip) {
            Log::info("Пропуск публикации order.status.changed (собственная синхронизация)", [
                'order_id' => $order['id'],
                'retailcrm_id' => $retailcrmId
            ]);
            return;
        }

        Log::info("Поставлено в outbox событие изменения статуса заказа: order.status.changed", [
            'order_id' => $order['id'],
            'status' => $order['status'],
        ]);
    }

    /**
     * Обрабатывает вебхук об изменении оплаты заказа.
     *
     * @param array $order Сконвертированный заказ
     * @return void
     */
    protected function handleOrderPaymentChange(array $order)
    {
        $retailcrmId = $order['__retailcrm_id'] ?? null;
        unset($order['__retailcrm_id']);

        if (!$retailcrmId) {
             Log::warning('handleOrderPaymentChange: отсутсвует retailcrm_id');
            return;
        }

        // Проверяем ПЕРЕД публикацией, является ли это событие результатом нашей собственной синхронизации
        $shouldSkip = $this->shouldSkipEvent(
            ['id' => $retailcrmId, 'externalId' => $order['externalId'] ?? null],
            'payment_change'
        );

        // Находим маппинг для заказа
        $mapping = OrderMapping::where('external_id', (string)$retailcrmId)->first();

        if (!$mapping) {
            Log::warning('Не найден маппинг для заказа с изменением оплаты из RetailCRM', [
                'retailcrm_id' => $retailcrmId,
            ]);
            return;
        }

        // Обновляем маппинг
        DB::transaction(function () use ($mapping, $order, $shouldSkip) {
            $mapping->data = $order;
            $mapping->save();

            // Публикуем событие только если НЕ нужно пропускать
            if (!$shouldSkip) {
                // Определяем тип события (оплата/возврат)
                $eventType = $order['is_paid'] ? 'order.paid' : 'order.refunded';
                $this->outbox->dispatch('order', $eventType, $order, $mapping->id);
            }
        });

        if ($shouldSkip) {
            Log::info("Пропуск публикации order.payment (собственная синхронизация)", [
                'order_id' => $order['id'],
                'retailcrm_id' => $retailcrmId
            ]);
            return;
        }

        Log::info("Поставлено в outbox событие изменения оплаты заказа", [
            'order_id' => $order['id'],
            'is_paid' => $order['is_paid'],
        ]);
    }

    /**
     * Обрабатывает вебхук об изменении доставки заказа.
     *
     * @param array $order Сконвертированный заказ
     * @return void
     */
    protected function handleOrderDeliveryChange(array $order)
    {
        $retailcrmId = $order['__retailcrm_id'] ?? null;
        unset($order['__retailcrm_id']);

        if (!$retailcrmId) {
             Log::warning('handleOrderDeliveryChange: отсутсвует retailcrm_id');
            return;
        }

        // Проверяем ПЕРЕД публикацией, является ли это событие результатом нашей собственной синхронизации
        $shouldSkip = $this->shouldSkipEvent(
            ['id' => $retailcrmId, 'externalId' => $order['externalId'] ?? null],
            'delivery_change'
        );

        // Находим маппинг для заказа
        $mapping = OrderMapping::where('external_id', (string)$retailcrmId)->first();

        if (!$mapping) {
            Log::warning('Не найден маппинг для заказа с изменением доставки из RetailCRM', [
                'retailcrm_id' => $retailcrmId,
            ]);
            return;
        }

        // Обновляем маппинг
        DB::transaction(function () use ($mapping, $order, $shouldSkip) {
            $mapping->data = $order;
            $mapping->save();

            // Публикуем событие только если НЕ нужно пропускать
            if (!$shouldSkip) {
                $this->outbox->dispatch('order', 'order.delivery.updated', $order, $mapping->id);
            }
        });

        if ($shouldSkip) {
            Log::info("Пропуск публикации order.delivery.updated (собственная синхронизация)", [
                'order_id' => $order['id'],
                'retailcrm_id' => $retailcrmId
            ]);
            return;
        }

        Log::info("Поставлено в outbox событие изменения доставки заказа", [
            'order_id' => $order['id'],
            'shipping_method' => $order['shipping_method'] ?? 'unknown',
        ]);
    }

    /**
     * Обрабатывает вебхук по клиенту.
     *
     * @param array $webhookData
     * @return \Illuminate\Http\JsonResponse
     */
    protected function processCustomerWebhook(array $webhookData)
    {
        $id = $webhookData['id'] ?? null;

        if (!$id) {
            return response()->json(['success' => false, 'error' => 'No ID provided'], 400);
        }

        try {
            // Запрашиваем данные клиента
            $response = $this->retailcrmService->getCustomers(['ids' => [$id]]);

            if (!$response || empty($response->customers)) {
                // Если клиент не найден, считаем его удаленным
                if ($this->handleCustomerDeletion((string)$id)) {
                     return response()->json(['success' => true]);
                }

                Log::error('Не удалось найти клиента в RetailCRM и маппинг отсутствует', ['id' => $id]);
                return response()->json(['success' => false, 'error' => 'Customer not found'], 404);
            }

            // Берем первого клиента
            // В зависимости от реализации SDK customers может быть массивом или ArrayObject
            $customers = $response->customers;
            $retailCrmCustomer = is_array($customers) ? reset($customers) : $customers[0];

            // Конвертируем в массив
            $customerData = json_decode(json_encode($retailCrmCustomer), true);

            $customer = $this->customerConverter->fromRetailCRM($customerData);

            Log::info("Клиент успешно конвертирован из RetailCRM", [
                'customer' => $customer,
                'retailcrm_id' => $id
            ]);

            // Добавляем raw retailcrm_id для внутреннего использования
            $customer['__retailcrm_id'] = $id;

            // Логика создания/обновления маппинга
            DB::transaction(function () use ($customer, $id) {
                // Ищем маппинг
                $mapping = CustomerMapping::where('external_id', (string)$id)->first();

                if ($mapping) {
                    // Проверка на изменения (опционально, аналогично заказам)
                    if ($this->isSameState($mapping->data, $customer)) {
                        Log::info("Пропуск обработки вебхука клиента: данные не изменились", ['id' => $id]);
                        return;
                    }

                    $mapping->data = $customer;
                    $mapping->save();
                    // Используем ID из маппинга
                    $customer['id'] = $mapping->id;
                    unset($customer['__retailcrm_id']);

                    $this->outbox->dispatch('customer', 'customer.updated', $customer, $mapping->id);
                    Log::info("Поставлено в outbox событие обновления клиента: customer.updated", ['id' => $customer['id']]);
                } else {
                    unset($customer['__retailcrm_id']);
                    $mapping = CustomerMapping::create([
                        'id' => $customer['id'],
                        'external_id' => (string)$id,
                        'source' => config('retailcrm.source'),
                        'data' => $customer,
                    ]);
                    $this->outbox->dispatch('customer', 'customer.created', $customer, $mapping->id);
                    Log::info("Поставлено в outbox событие создания клиента: customer.created", ['id' => $customer['id']]);
                }
            });

            Log::info("Обработан вебхук клиента", ['id' => $id]);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error("Ошибка при обработке вебхука клиента: {$e->getMessage()}", [
                'exception' => $e,
                'id' => $id
            ]);
            return response()->json(['success' => false, 'error' => 'Internal Server Error'], 500);
        }
    }
}
