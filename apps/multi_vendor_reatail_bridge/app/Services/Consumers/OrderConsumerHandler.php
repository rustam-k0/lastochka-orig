<?php

namespace App\Services\Consumers;

use App\Models\Mapping\OrderMapping;
use App\Models\System\EventSyncMetadata;
use App\Services\Converters\OrderConverter;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Обработчик событий заказов (order.*).
 */
class OrderConsumerHandler extends BaseConsumerHandler
{
    protected string $entityType = 'order';

    protected OrderConverter $converter;
    protected RetailCRMService $retailCrmService;

    public function __construct(OrderConverter $converter, RetailCRMService $retailCrmService)
    {
        $this->converter = $converter;
        $this->retailCrmService = $retailCrmService;
    }

    /**
     * Валидация структуры заказа.
     *
     * Для событий status_changed не требуем items — достаточно id, source и status.
     * Для остальных событий (created, updated) items обязательны.
     */
    protected function validateStructure(array $data): bool
    {
        if (!parent::validateStructure($data)) {
            return false;
        }

        // Для status_changed не требуем items
        if ($this->isStatusChangedEvent($data)) {
            return isset($data['status']) || isset($data['new_status']);
        }

        // Для остальных событий items обязательны
        return isset($data['items'])
            && is_array($data['items'])
            && count($data['items']) > 0;
    }

    /**
     * Определяет, является ли событие сменой статуса.
     *
     * Проверяет event_type в данных.
     */
    protected function isStatusChangedEvent(array $data): bool
    {
        return ($data['event_type'] ?? null) === 'status_changed';
    }

    /**
     * Маппит системный статус в статус RetailCRM через конфиг.
     *
     * @param string $status Системный статус (например, 'in_delivery')
     * @return string|null Статус RetailCRM (например, 'delivering') или null если маппинг не найден
     */
    protected function mapStatusToRetailCRM(string $status): ?string
    {
        $statusMap = config('retailcrm.order_statuses', []);

        return $statusMap[$status] ?? null;
    }

    /**
     * Синхронизирует заказ с RetailCRM.
     *
     * Для событий status_changed — обновляет только статус (без processCustomer/processOrderItems).
     * Для остальных событий — полная синхронизация заказа.
     */
    protected function syncToRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?string {
        // Отдельная ветка для обновления статуса
        if ($this->isStatusChangedEvent($data)) {
            return $this->syncStatusChangeToRetailCRM($data, $metadata);
        }

        try {
            // Конвертируем данные в формат RetailCRM
            $orderData = $this->converter->toRetailCRM($data);
            $orderData['site'] = $data['shop_code'] ?? config('retailcrm.shop_code', 'default');

            // Обрабатываем клиента
            $orderData = $this->processCustomer($data, $orderData);

            // Обрабатываем товары в составе заказа
            $orderData = $this->processOrderItems($data, $orderData);

            // Fallback: ищем в OrderMapping если entity_id пустой
            if (!$metadata->entity_id) {
                $legacyMapping = OrderMapping::where('id', $data['id'] ?? '')->first();
                if ($legacyMapping && $legacyMapping->external_id) {
                    Log::info("entity_id найден в OrderMapping (legacy) для order sync", [
                        'external_id' => $data['id'],
                        'retail_id' => $legacyMapping->external_id,
                    ]);
                    $metadata->entity_id = $legacyMapping->external_id;
                    $metadata->save();
                }
            }

            // Если заказ уже существует в RetailCRM (есть entity_id), обновляем
            if ($metadata->entity_id) {
                try {
                    // Передаем entity_id явно, чтобы обновить по внутреннему ID CRM
                    $response = $this->retailCrmService->updateOrder($orderData, 'id', $metadata->entity_id);

                    if ($response && isset($response->id)) {
                        return (string)$response->id;
                    }
                } catch (\App\Exceptions\RetailCRM\RetailCrmNotFoundException $e) {
                    // Если заказ не найден (404), логируем и переходим к созданию
                    Log::warning("Не удалось обновить заказ {$metadata->entity_id} (404 Not Found). Пробуем создать заново.", [
                        'external_id' => $data['id'],
                        'retail_id' => $metadata->entity_id
                    ]);
                    // Только при 404 разрешаем fallthrough к созданию
                    $response = $this->retailCrmService->createOrder($orderData);

                    if ($response && isset($response->id)) {
                        return (string)$response->id;
                    }

                    Log::error("Не удалось создать заказ в RetailCRM после 404", [
                        'external_id' => $data['id'],
                        'response' => $response,
                    ]);

                    return null;
                }

                // Если обновление не удалось по другой причине (не 404) — НЕ создаём новый,
                // чтобы не порождать дубли в RetailCRM
                Log::error("Не удалось обновить заказ {$metadata->entity_id} (ошибка запроса). Дубль НЕ создаётся.", [
                    'external_id' => $data['id'],
                    'retail_id' => $metadata->entity_id
                ]);

                return null;
            }

            // Создаем новый заказ только если entity_id ещё не назначен
            $response = $this->retailCrmService->createOrder($orderData);

            if ($response && isset($response->id)) {
                return (string)$response->id;
            }

            Log::error("Не удалось создать заказ в RetailCRM", [
                'external_id' => $data['id'],
                'response' => $response,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("Ошибка синхронизации заказа с RetailCRM: {$e->getMessage()}", [
                'exception' => $e,
                'external_id' => $data['id'],
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Синхронизирует только смену статуса заказа с RetailCRM.
     *
     * Не вызывает processCustomer/processOrderItems — для status_changed
     * достаточно обновить статус через updateOrderStatus().
     *
     * @param array $data Данные события
     * @param EventSyncMetadata $metadata Метаданные синхронизации
     * @return string|null ID заказа в RetailCRM или null при ошибке
     */
    protected function syncStatusChangeToRetailCRM(array $data, EventSyncMetadata $metadata): ?string
    {
        try {
            // Заказ должен уже существовать в RetailCRM
            if (!$metadata->entity_id) {
                // Fallback: ищем в старой таблице OrderMapping (заказы, созданные через OrderEventHandler)
                $legacyMapping = OrderMapping::where('id', $data['id'] ?? '')->first();

                if ($legacyMapping && $legacyMapping->external_id) {
                    Log::info("entity_id найден в OrderMapping (legacy)", [
                        'external_id' => $data['id'],
                        'retail_id' => $legacyMapping->external_id,
                    ]);
                    $metadata->entity_id = $legacyMapping->external_id;
                    $metadata->save();
                } else {
                    Log::warning("Невозможно обновить статус заказа — заказ не найден в RetailCRM (нет entity_id)", [
                        'external_id' => $data['id'] ?? 'unknown',
                        'status' => $data['new_status'] ?? $data['status'] ?? 'unknown',
                    ]);
                    return null;
                }
            }

            // Берём новый статус: приоритет у new_status, fallback на status
            $systemStatus = $data['new_status'] ?? $data['status'] ?? null;

            if (!$systemStatus) {
                Log::error("Отсутствует статус в событии status_changed", [
                    'external_id' => $data['id'] ?? 'unknown',
                    'data' => $data,
                ]);
                return null;
            }

            // Маппим системный статус в статус RetailCRM
            $retailCrmStatus = $this->mapStatusToRetailCRM($systemStatus);

            if (!$retailCrmStatus) {
                Log::warning("Неизвестный системный статус, маппинг не найден", [
                    'external_id' => $data['id'] ?? 'unknown',
                    'system_status' => $systemStatus,
                    'available_statuses' => array_keys(config('retailcrm.order_statuses', [])),
                ]);
                return null;
            }

            $site = $data['shop_code'] ?? config('retailcrm.shop_code', 'default');

            Log::info("Обновление статуса заказа в RetailCRM", [
                'external_id' => $data['id'] ?? 'unknown',
                'entity_id' => $metadata->entity_id,
                'old_status' => $data['old_status'] ?? 'unknown',
                'new_status' => $systemStatus,
                'retailcrm_status' => $retailCrmStatus,
            ]);

            $response = $this->retailCrmService->updateOrderStatus(
                $metadata->entity_id,
                $retailCrmStatus,
                $site
            );

            if ($response && isset($response->id)) {
                return (string)$response->id;
            }

            // updateOrderStatus может вернуть response без ->id при успехе
            // Если response не false, считаем успехом и возвращаем текущий entity_id
            if ($response !== false && $response !== null) {
                return $metadata->entity_id;
            }

            Log::error("Не удалось обновить статус заказа в RetailCRM", [
                'external_id' => $data['id'] ?? 'unknown',
                'entity_id' => $metadata->entity_id,
                'retailcrm_status' => $retailCrmStatus,
                'response' => $response,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("Ошибка при обновлении статуса заказа в RetailCRM: {$e->getMessage()}", [
                'exception' => $e,
                'external_id' => $data['id'] ?? 'unknown',
                'entity_id' => $metadata->entity_id ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Обрабатывает клиента заказа.
     * Ищет клиента через метаданные, если указан client_id.
     */
    protected function processCustomer(array $data, array $orderData): array
    {
        // Если указан ID клиента и источник, ищем его в метаданных
        if (!empty($data['client_id']) && !empty($data['client_source'])) {
            $customerRetailId = $this->findRetailCrmId(
                'customer',
                $data['client_source'],
                $data['client_id'],
                $data['shop_code'] ?? null
            );

            if ($customerRetailId) {
                $orderData['customer'] = [
                    'id' => (int)$customerRetailId,
                ];
                
                Log::debug("Клиент найден в метаданных для заказа", [
                    'order_external_id' => $data['id'],
                    'customer_external_id' => $data['client_id'],
                    'customer_retail_id' => $customerRetailId,
                ]);
            } else {
                Log::warning("Клиент не найден в метаданных для заказа. Пробуем создать/синхронизировать.", [
                    'order_external_id' => $data['id'],
                    'customer_external_id' => $data['client_id'],
                    'customer_source' => $data['client_source'],
                ]);

                // Попытка создать клиента в RetailCRM
                $customerData = $orderData['customer'] ?? [];
                
                // Дополняем данными из события, если в orderData неполные
                if (empty($customerData['firstName'])) $customerData['firstName'] = $data['client_info']['name'] ?? '';
                if (empty($customerData['lastName'])) $customerData['lastName'] = $data['client_info']['surname'] ?? '';
                if (empty($customerData['email'])) $customerData['email'] = $data['client_info']['email'] ?? null;
                if (empty($customerData['phones']) && !empty($data['client_info']['phone'])) {
                    $customerData['phones'] = [['number' => $data['client_info']['phone']]];
                }
                
                $customerData['externalId'] = $data['client_id'];
                $customerData['site'] = $data['shop_code'] ?? config('retailcrm.shop_code', 'default');

                try {
                    // 1. Сначала ищем по externalId
                    $existingCustomer = $this->retailCrmService->getCustomers(['externalId' => $data['client_id']]);
                    $createdId = null;

                    if ($existingCustomer && !empty($existingCustomer->customers)) {
                        $createdId = $existingCustomer->customers[0]->id;
                        Log::debug("Клиент найден по externalId в RetailCRM", [
                            'external_id' => $data['client_id'],
                            'retail_id' => $createdId,
                        ]);
                    }
                    
                    // 2. Если не найден по externalId - ищем по телефону (для дедупликации)
                    if (!$createdId && !empty($data['client_info']['phone'])) {
                        // Фильтр 'name' в RetailCRM ищет по ФИО или телефону
                        // Передаём телефон как есть без нормализации, т.к. RetailCRM делает fuzzy match
                        $foundByPhone = $this->retailCrmService->getCustomers(['name' => $data['client_info']['phone']]);
                        
                        if ($foundByPhone && !empty($foundByPhone->customers)) {
                            $createdId = $foundByPhone->customers[0]->id;
                            Log::info("Клиент найден по телефону в RetailCRM - предотвращено дублирование", [
                                'phone' => $data['client_info']['phone'],
                                'retail_id' => $createdId,
                                'external_id' => $data['client_id'],
                            ]);
                        }
                    }
                    
                    // 3. Если не найден по email - ищем по email
                    if (!$createdId && !empty($data['client_info']['email'])) {
                        $foundByEmail = $this->retailCrmService->getCustomers(['email' => $data['client_info']['email']], 1, 10);
                        
                        if ($foundByEmail && !empty($foundByEmail->customers)) {
                            $createdId = $foundByEmail->customers[0]->id;
                            Log::info("Клиент найден по email в RetailCRM - предотвращено дублирование", [
                                'email' => $data['client_info']['email'],
                                'retail_id' => $createdId,
                                'external_id' => $data['client_id'],
                            ]);
                        }
                    }
                    
                    // 4. Только если нигде не найден - создаём нового
                    if (!$createdId) {
                        $resp = $this->retailCrmService->createCustomer($customerData);
                        if ($resp && isset($resp->id)) {
                            $createdId = $resp->id;
                            Log::info("Создан новый клиент в RetailCRM", [
                                'retail_id' => $createdId,
                                'external_id' => $data['client_id'],
                            ]);
                        }
                    }

                    if ($createdId) {
                        // Сохраняем метаданные (INSERT-first)
                        $this->getOrCreateMetadata(
                            'customer', // используем 'customer' как entityType для клиента
                            $data['client_id'], // externalId
                            $data['shop_code'] ?? null
                        );
                        // Обновляем entity_id в метаданных
                        $meta = EventSyncMetadata::where('entity_type', 'customer')
                            ->where('external_id', $data['client_id'])
                            ->first();
                        if ($meta) {
                            $meta->entity_id = (string)$createdId;
                            $meta->save();
                        }

                        // Привязываем к заказу
                        $orderData['customer'] = ['id' => (int)$createdId];
                        
                        Log::info("Клиент успешно создан/найден и привязан к заказу", [
                             'customer_id' => $createdId,
                             'external_id' => $data['client_id']
                        ]);
                    }

                } catch (\Exception $e) {
                    Log::error("Ошибка при создании клиента в processCustomer: " . $e->getMessage());
                }
            }
        }

        return $orderData;
    }

    /**
     * Обрабатывает товары в составе заказа.
     * Ищет товары через метаданные и актуализирует Offer ID через API RetailCRM.
     */
    protected function processOrderItems(array $data, array $orderData): array
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            return $orderData;
        }

        $items = [];
        $productIdsToFetch = [];
        $itemMap = []; // index => metadataId

        foreach ($data['items'] as $index => $item) {
            $orderItem = [
                'productName' => $item['product_name'] ?? 'Неизвестный товар',
                'quantity' => $item['quantity'] ?? 1,
                'initialPrice' => $item['unit_price'] ?? 0,
                'discountManualAmount' => $item['line_discount_total'] ?? 0,
            ];

            // 1. Проверка дубликатов позиций (order_item)
            // Если у позиции есть внешний ID, проверяем метаданные
            if (!empty($item['id'])) {
                // Ищем метаданные для этой позиции
                $itemMetadata = $this->getOrCreateMetadata(
                    'order_item',
                    (string)$item['id'],
                    $data['shop_code'] ?? null
                );

                if ($itemMetadata && $itemMetadata->entity_id) {
                    $orderItem['id'] = (int)$itemMetadata->entity_id;
                }
            }

            // 2. Ищем товар (product/offer) в метаданных
            if (!empty($item['product_id']) && !empty($item['product_source'])) {
                $productRetailId = $this->findRetailCrmId(
                    'product',
                    $item['product_source'],
                    $item['product_id'],
                    $data['shop_code'] ?? null
                );

                if ($productRetailId) {
                    // Собираем ID для пакетного запроса
                    $productIdsToFetch[$productRetailId] = $productRetailId;
                    $itemMap[$index] = $productRetailId;
                } else {
                    Log::warning("Товар не найден в метаданных для позиции заказа", [
                        'order_external_id' => $data['id'],
                        'product_external_id' => $item['product_id'],
                        'product_source' => $item['product_source'],
                    ]);
                }
            }

            $items[$index] = $orderItem;
        }

        // Если есть найденные ID, запрашиваем товары из RetailCRM для получения актуальных Offer ID
        $offerIdMap = [];
        if (!empty($productIdsToFetch)) {
            try {
                $response = $this->retailCrmService->getProducts(['ids' => array_values($productIdsToFetch)]);
                
                if ($response && !empty($response->products)) {
                    foreach ($response->products as $product) {
                        $productId = (string)$product->id;
                        // Берем ID первого торгового предложения (оффера)
                        if (!empty($product->offers)) {
                            $offerIdMap[$productId] = (string)$product->offers[0]->id;
                        } else {
                            // Fallback на ID товара
                            $offerIdMap[$productId] = $productId;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Ошибка при актуализации Offer ID товаров: " . $e->getMessage());
            }
        }

        // Присваиваем ID офферов позициям заказа
        foreach ($items as $index => &$orderItem) {
            if (isset($itemMap[$index])) {
                $metadataId = $itemMap[$index];
                // Используем Offer ID если нашли, иначе оставляем ID из метаданных
                $finalId = $offerIdMap[$metadataId] ?? $metadataId;
                
                $orderItem['offer'] = [
                    'id' => (int)$finalId,
                ];
            }
        }

        if (!empty($items)) {
            $orderData['items'] = array_values($items);
        }

        return $orderData;
    }

    /**
     * Синхронизирует метаданные позиций заказа.
     * Запрашивает актуальный заказ из RetailCRM и сопоставляет ID позиций.
     */
    protected function syncOrderItemsMetadata(string $retailOrderId, array $sourceItems, ?string $shopCode): void
    {
        try {
            // Запрашиваем заказ из RetailCRM, чтобы получить присвоенные ID позиций
            $response = $this->retailCrmService->getOrder((int)$retailOrderId, $shopCode);

            if (!$response || !isset($response->order) || empty($response->order['items'])) {
                return;
            }

            $retailItems = $response->order['items'];
            $retailItemsMap = [];

            // Индексируем позиции из CRM по externalId
            foreach ($retailItems as $rItem) {
                if (!empty($rItem['externalId'])) {
                    $retailItemsMap[$rItem['externalId']] = $rItem['id'];
                }
            }

            foreach ($sourceItems as $sItem) {
                if (empty($sItem['id'])) continue;

                $externalId = (string)$sItem['id'];

                // Если нашли соответствие
                if (isset($retailItemsMap[$externalId])) {
                    $retailId = (string)$retailItemsMap[$externalId];

                    // Обновляем метаданные
                    // Используем getOrCreateMetadata, так как запись могла быть создана в processOrderItems
                    $metadata = $this->getOrCreateMetadata('order_item', $externalId, $shopCode);
                    
                    if ($metadata && $metadata->entity_id !== $retailId) {
                        $metadata->entity_id = $retailId;
                        $metadata->save();
                        
                        Log::debug("Обновлены метаданные позиции заказа", [
                            'external_id' => $externalId,
                            'retail_id' => $retailId
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Ошибка при синхронизации метаданных позиций заказа: " . $e->getMessage());
        }
    }

    /**
     * Нормализует номер телефона для поиска в RetailCRM.
     * Удаляет все символы кроме цифр и приводит к формату без кода страны.
     */
    protected function normalizePhone(string $phone): string
    {
        // Удаляем все кроме цифр
        $digits = preg_replace('/\D/', '', $phone);
        
        // Если начинается с 8 или 7 и длина 11 - убираем первую цифру
        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'])) {
            $digits = substr($digits, 1);
        }
        
        return $digits;
    }
}
