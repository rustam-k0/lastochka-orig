<?php

namespace App\Services\Consumers;

use App\Models\System\EventSyncMetadata;
use App\Support\ShopCodeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Базовый обработчик событий Consumer с паттерном INSERT-first.
 *
 * Реализует алгоритм обработки событий:
 * 1. Валидация структуры JSON
 * 2. Loopback prevention (event_source === currentSource) - пропуск событий отправленных нами
 * 3. Sync decision (shouldSyncToRetailCRM) - определение нужна ли синхронизация в RetailCRM
 * 4. Metadata Lookup (INSERT-first pattern) - поиск/создание метаданных
 * 5. Идемпотентность (сравнение event_id и timestamp) - предотвращение дублей
 * 6. Синхронизация с RetailCRM (если нужно)
 * 7. Обновление метаданных
 *
 * Сценарии обработки:
 * - source=retailbridge, event_source=retailbridge → ПРОПУСТИТЬ (loopback)
 * - source=retailbridge, event_source=admin → СИНХРОНИЗИРОВАТЬ (наша сущность изменена в админке)
 * - source=retailcrm, event_source=retailbridge → ПРОПУСТИТЬ (loopback)
 * - source=retailcrm, event_source=admin → ОБНОВИТЬ МЕТАДАННЫЕ (не синхронизируем, но трекаем)
 * - source=bagisto, event_source=bagisto → СИНХРОНИЗИРОВАТЬ (внешняя сущность)
 */
abstract class BaseConsumerHandler
{
    /**
     * Тип сущности (product, category, order, customer, user)
     */
    protected string $entityType;

    /**
     * Главный метод обработки события.
     *
     * @param array $data Данные события из RabbitMQ
     * @param AMQPMessage $message Сырое сообщение RabbitMQ
     * @return bool True если успешно обработано
     */
    public function handle(array $data, AMQPMessage $message): bool
    {
        Log::info("Обработка события для {$this->entityType}", [
            'event_id' => $data['event_id'] ?? 'unknown',
            'external_id' => $data['id'] ?? 'unknown',
            'source' => $data['source'] ?? 'unknown',
            'event_source' => $data['event_source'] ?? 'unknown',
        ]);

        // 1. Валидация структуры
        if (!$this->validateStructure($data)) {
            Log::error("Неверная структура данных для {$this->entityType}", [
                'data' => $data,
            ]);
            return true; // ACK чтобы не обрабатывать снова
        }

        $currentSource = config('rabbitmq-events.source', 'retailbridge');
        $retailcrmSource = config('retailcrm.source', 'retailcrm');

        // 2. Loopback prevention - пропускаем события от самих себя
        // event_source показывает КТО отправил событие (не путать с source - владелец сущности)
        if (isset($data['event_source']) && $data['event_source'] === $currentSource) {
            Log::info("Пропуск loopback события (event_source === currentSource)", [
                'event_id' => $data['event_id'] ?? 'unknown',
                'event_source' => $data['event_source'],
                'current_source' => $currentSource,
                'source' => $data['source'] ?? 'unknown',
                'reason' => 'Loopback prevention - событие отправлено нами',
            ]);
            return true; // ACK
        }

        try {
            $source = $data['source'] ?? 'unknown';
            $externalId = (string)($data['id'] ?? null);
            $shopCode = $data['shop_code'] ?? null;
            $eventId = $data['event_id'] ?? null;
            $eventTimestamp = $data['timestamp'] ?? time();

            if (!$externalId) {
                Log::error("Отсутствует ID сущности в сообщении для {$this->entityType}", ['data' => $data]);
                return false;
            }


            // 3-7. Обработка с использованием метаданных и идемпотентности
            // ВАЖНО: обрабатываем метаданные ВСЕГДА, даже если не синхронизируем
            // Это нужно для:
            // - Обновления last_event_id и last_event_timestamp (идемпотентность)
            // - Отслеживания изменений сущностей из RetailCRM
            return $this->processWithMetadata(
                $source,
                $externalId,
                $shopCode,
                $eventId,
                $eventTimestamp,
                $data,
                $message
            );

        } catch (\Exception $e) {
            Log::error("Ошибка обработки сообщения для {$this->entityType}: {$e->getMessage()}", [
                'exception' => $e,
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Обработка с использованием метаданных (INSERT-first pattern).
     *
     * @param string $source Источник данных
     * @param string $externalId Внешний ID сущности
     * @param string|null $shopCode Код магазина
     * @param string|null $eventId ID события
     * @param int $eventTimestamp Timestamp события
     * @param array $data Данные события
     * @param AMQPMessage $message Оригинальное AMQP сообщение
     * @param bool $shouldSyncToRetailCRM Нужно ли синхронизировать в RetailCRM
     * @return bool
     */
    protected function processWithMetadata(
        string $source,
        string $externalId,
        ?string $shopCode,
        ?string $eventId,
        int $eventTimestamp,
        array $data,
        AMQPMessage $message
    ): bool {
        return DB::transaction(function () use (
            $source,
            $externalId,
            $shopCode,
            $eventId,
            $eventTimestamp,
            $data,
            $message
        ) {
            // 3. Metadata Lookup (INSERT-first)
            $metadata = $this->getOrCreateMetadata($source, $externalId, $shopCode);

            if (!$metadata) {
                Log::error("Не удалось получить или создать метаданные для {$this->entityType}", [
                    'source' => $source,
                    'external_id' => $externalId,
                    'shop_code' => $shopCode,
                ]);
                return false;
            }

            // 3-4. Идемпотентность: проверка event_id и timestamp
            if ($this->isEventAlreadyProcessed($metadata, $eventId, $eventTimestamp)) {
                Log::debug("Событие уже обработано для {$this->entityType}", [
                    'source' => $source,
                    'external_id' => $externalId,
                    'event_id' => $eventId,
                ]);
                return true; // Успешно пропустили дубликат
            }

            // 5. Проверка на событие удаления
            if ($this->isDeleteEvent($data, $message)) {
                return $this->processDeleteEvent($data, $metadata, $message, $eventId, $eventTimestamp);
            }

            // 6. Синхронизация с RetailCRM (если нужно)
            $retailCrmId = null;

            $retailCrmId = $this->syncToRetailCRM($data, $metadata, $message);

            if (!$retailCrmId) {
                throw new \RuntimeException("Не удалось синхронизировать с RetailCRM для {$this->entityType} (source: {$source}, external_id: {$externalId})");
            }

            // 7. Обновление entity_id в метаданных
            $metadata->entity_id = $retailCrmId;


            // 8. Обновление метаданных события (всегда)
            $metadata->last_event_id = $eventId;
            $metadata->last_event_timestamp = now()->setTimestamp($eventTimestamp);
            $metadata->save();

            Log::info("Успешно обработано событие для {$this->entityType}", [
                'source' => $source,
                'external_id' => $externalId,
                'retail_crm_id' => $retailCrmId ?? $metadata->entity_id ?? 'N/A',
                'event_id' => $eventId
            ]);

            return true;
        });
    }

    /**
     * Получает или создает запись метаданных (INSERT-first pattern).
     *
     * Логика работы:
     * - Если source === currentSource → это ЛОКАЛЬНАЯ сущность (созданная здесь, изменена в другом сервисе)
     *   → ищем по entity_id
     *   → если не найдено - создаем новые метаданные (entity_id = external_id)
     * - Если source !== currentSource → это ВНЕШНЯЯ сущность (созданная в другом сервисе)
     *   → ищем/создаем по external_id
     *
     * @param string $source Источник-владелец сущности
     * @param string $externalId ID сущности (для локальных = entity_id, для внешних = external_id)
     * @param string|null $shopCode
     * @return EventSyncMetadata|null
     */
    protected function getOrCreateMetadata(
        string $source,
        string $externalId,
        ?string $shopCode
    ): ?EventSyncMetadata {
        $currentSource = config('rabbitmq-events.source', 'retailbridge');

        try {
            // ✅ CRITICAL: Разная логика для локальных и внешних сущностей
            if ($source === $currentSource) {
                // 🏠 ЛОКАЛЬНАЯ сущность - другой сервис изменил нашу сущность
                // ID в событии это наш entity_id, ищем по нему
                Log::info("🏠 Обработка локальной сущности", [
                    'entity_type' => $this->entityType,
                    'entity_id' => $externalId,
                    'source' => $source,
                ]);

                $metadata = EventSyncMetadata::where('entity_type', $this->entityType)
                    ->where('entity_id', $externalId)
                    ->lockForUpdate()
                    ->first();

                if ($metadata) {
                    Log::info("✅ Найдены метаданные локальной сущности", [
                        'metadata_id' => $metadata->id,
                        'entity_id' => $metadata->entity_id,
                    ]);
                    return $metadata;
                }

                // Метаданные не найдены - создаём (INSERT-first)
                // Это может произойти если сущность создана в другом сервисе с нашим source
                Log::info("🔵 Создание метаданных для локальной сущности", [
                    'entity_id' => $externalId,
                ]);

                try {
                    $metadata = EventSyncMetadata::create([
                        'entity_type' => $this->entityType,
                        'source' => $source,
                        'external_id' => $externalId,
                        'shop_code' => $shopCode,
                        'entity_id' => $externalId, // Для локальных: entity_id = external_id
                    ]);

                    Log::info("✅ Метаданные созданы", ['metadata_id' => $metadata->id]);
                    return $metadata;

                } catch (QueryException $e) {
                    // Race condition
                    if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        Log::warning("⚠️ Race condition при создании метаданных, повторный SELECT");
                        return EventSyncMetadata::where('entity_type', $this->entityType)
                            ->where('entity_id', $externalId)
                            ->lockForUpdate()
                            ->first();
                    }
                    throw $e;
                }
            } else {
                // 🌍 ВНЕШНЯЯ сущность - создана в другом сервисе, нужно создать/обновить у нас
                Log::info("🌍 Обработка внешней сущности", [
                    'entity_type' => $this->entityType,
                    'external_id' => $externalId,
                    'source' => $source,
                ]);

                // Сначала пытаемся найти
                $metadata = EventSyncMetadata::where([
                    'entity_type' => $this->entityType,
                    'source' => $source,
                    'external_id' => $externalId,
                    'shop_code' => $shopCode,
                ])->first();

                if ($metadata) {
                    Log::info("✅ Найдены метаданные внешней сущности", [
                        'metadata_id' => $metadata->id,
                    ]);
                    return $metadata;
                }

                // Не нашли - создаем новую запись (INSERT-first)
                try {
                    Log::info("🔵 Создание метаданных для внешней сущности");

                    $metadata = EventSyncMetadata::create([
                        'entity_type' => $this->entityType,
                        'source' => $source,
                        'external_id' => $externalId,
                        'shop_code' => $shopCode,
                        'entity_id' => null, // NULL до создания entity
                    ]);

                    Log::info("✅ Метаданные созданы", ['metadata_id' => $metadata->id]);
                    return $metadata;

                } catch (QueryException $e) {
                    // Race condition: другой процесс создал между SELECT и INSERT
                    if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        Log::warning("⚠️ Race condition при создании метаданных, повторный SELECT", [
                            'entity_type' => $this->entityType,
                            'source' => $source,
                            'external_id' => $externalId,
                        ]);

                        // Повторяем SELECT
                        return EventSyncMetadata::where([
                            'entity_type' => $this->entityType,
                            'source' => $source,
                            'external_id' => $externalId,
                            'shop_code' => $shopCode,
                        ])->lockForUpdate()->first();
                    }

                    throw $e;
                }
            }
        } catch (\Exception $e) {
            Log::error("Ошибка при работе с метаданными: {$e->getMessage()}", [
                'exception' => $e,
                'entity_type' => $this->entityType,
                'source' => $source,
                'external_id' => $externalId,
            ]);
            return null;
        }
    }

    /**
     * Определяет, является ли событие удалением.
     *
     * Проверяет:
     * 1. Наличие непустого deleted_at в данных
     * 2. Routing key содержит 'deleted'
     */
    protected function isDeleteEvent(array $data, AMQPMessage $message): bool
    {
        if (!empty($data['deleted_at'])) {
            return true;
        }

        $routingKey = $message->getRoutingKey();
        if ($routingKey && str_contains($routingKey, 'deleted')) {
            return true;
        }

        return false;
    }

    /**
     * Обрабатывает событие удаления.
     *
     * Если есть entity_id в metadata — деактивирует в RetailCRM.
     * Если нет — просто обновляет metadata и ACK.
     */
    protected function processDeleteEvent(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message,
        ?string $eventId,
        int $eventTimestamp
    ): bool {
        if ($metadata->entity_id) {
            $result = $this->deactivateInRetailCRM($data, $metadata, $message);

            if ($result === false) {
                Log::warning("Не удалось деактивировать {$this->entityType} в RetailCRM, пропускаем", [
                    'entity_id' => $metadata->entity_id,
                    'external_id' => $data['id'] ?? 'unknown',
                ]);
            }
        } else {
            Log::info("Delete event для {$this->entityType} без entity_id — пропуск деактивации", [
                'external_id' => $data['id'] ?? 'unknown',
            ]);
        }

        $metadata->last_event_id = $eventId;
        $metadata->last_event_timestamp = now()->setTimestamp($eventTimestamp);
        $metadata->save();

        Log::info("Обработано delete событие для {$this->entityType}", [
            'external_id' => $data['id'] ?? 'unknown',
            'entity_id' => $metadata->entity_id ?? 'N/A',
            'event_id' => $eventId,
        ]);

        return true;
    }

    /**
     * Деактивирует сущность в RetailCRM.
     *
     * По умолчанию возвращает null (не реализовано).
     * Наследники переопределяют для конкретных типов сущностей.
     *
     * @return bool|null true — успешно, false — ошибка, null — не реализовано
     */
    protected function deactivateInRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?bool {
        Log::info("deactivateInRetailCRM не реализован для {$this->entityType}, пропуск");
        return null;
    }

    /**
     * Проверяет, было ли событие уже обработано.
     *
     * @param EventSyncMetadata $metadata
     * @param string|null $eventId
     * @param int $eventTimestamp
     * @return bool
     */
    protected function isEventAlreadyProcessed(
        EventSyncMetadata $metadata,
        ?string $eventId,
        int $eventTimestamp
    ): bool {
        // Проверка по event_id
        if ($eventId && $metadata->last_event_id === $eventId) {
            return true;
        }

        // Проверка по timestamp (если новое событие старее последнего обработанного)
        if ($metadata->last_event_timestamp) {
            $lastTimestamp = $metadata->last_event_timestamp->getTimestamp();
            if ($eventTimestamp <= $lastTimestamp) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверяет, является ли событие loopback (от самого сервиса).
     *
     * @param array $data
     * @return bool
     */
    protected function isLoopback(array $data): bool
    {
        $source = $data['source'] ?? null;
        return $source === $this->serviceSource;
    }

    /**
     * Валидирует структуру входящих данных.
     * Должен быть переопределен в наследниках для специфичной валидации.
     *
     * @param array $data
     * @return bool
     */
    protected function validateStructure(array $data): bool
    {
        // Базовая валидация - наличие обязательных полей
        return isset($data['id']) && isset($data['source']);
    }

    /**
     * Синхронизирует данные с RetailCRM.
     * Должен быть реализован в наследниках.
     *
     * @param array $data Данные события
     * @param EventSyncMetadata $metadata Метаданные синхронизации
     * @param AMQPMessage $message Оригинальное AMQP сообщение
     * @return string|null ID созданной/обновленной сущности в RetailCRM
     */
    abstract protected function syncToRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?string;

    protected \App\Services\RetailCRM\RetailCRMService $retailCrmService;

    /**
     * Ищет ID сущности в RetailCRM через метаданные.
     * Используется для связанных сущностей (например, parent_id для категорий).
     *
     * @param string $entityType Тип сущности
     * @param string $source Источник
     * @param string $externalId Внешний ID
     * @param string|null $shopCode Код магазина
     * @return string|null ID в RetailCRM или null если не найдено
     */
    protected function findRetailCrmId(
        string $entityType,
        string $source,
        string $externalId,
        ?string $shopCode = null
    ): ?string {
        $metadata = EventSyncMetadata::where([
            'entity_type' => $entityType,
            'source' => $source,
            'external_id' => $externalId,
            'shop_code' => $shopCode,
        ])->first();

        if ($metadata) {
            return $metadata->entity_id;
        }

        // Если сущность из RetailCRM, но метаданных нет — пробуем найти или создать в RetailCRM
        $retailCrmSource = config('retailcrm.source', 'retailcrm');
        if ($source === $retailCrmSource) {
            Log::info("🔎 Сущность из RetailCRM ({$entityType} ID: {$externalId}) не найдена в метаданных. Пробуем получить реальный ID из CRM.", [
                'entity_type' => $entityType,
                'source' => $source,
                'external_id' => $externalId
            ]);

            try {
                // 1. Пробуем найти сущность в RetailCRM
                $realId = $this->fetchRetailCrmEntity($entityType, $externalId);

                // 2. Если не нашли — пробуем создать заглушку (чтобы восстановить целостность)
                if (!$realId) {
                    Log::warning("⚠️ Сущность {$entityType} ID: {$externalId} не найдена в RetailCRM. Пробуем создать заглушку.");
                    $realId = $this->createRetailCrmEntityStub($entityType, $externalId, $shopCode);
                }

                if ($realId) {
                    // Создаем метаданные с реальным ID
                    $metadata = EventSyncMetadata::create([
                        'entity_type' => $entityType,
                        'source' => $source,
                        'external_id' => $externalId,
                        'shop_code' => $shopCode,
                        'entity_id' => $realId,
                        'last_event_timestamp' => now(),
                    ]);
                    
                    Log::info("✅ Метаданные созданы/восстановлены", [
                        'entity_id' => $realId, 
                        'external_id' => $externalId
                    ]);
                    
                    return $metadata->entity_id;
                }

            } catch (\Exception $e) {
                Log::warning("⚠️ Ошибка восстановления сущности в findRetailCrmId: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Пытается получить реальный ID сущности из RetailCRM.
     */
    protected function fetchRetailCrmEntity(string $entityType, string $externalId): ?string
    {
        if (!isset($this->retailCrmService)) {
            return null;
        }

        try {
            switch ($entityType) {
                case 'category':
                    $response = $this->retailCrmService->getProductGroups(['ids' => [$externalId]]);
                    if ($response->success && !empty($response->productGroup)) {
                        // Ищем точное совпадение ID
                        foreach ($response->productGroup as $group) {
                            if ((string)$group->id === $externalId || (string)($group->externalId ?? '') === $externalId) {
                                return (string)$group->id;
                            }
                        }
                    }
                    break;

                case 'product':
                    $response = $this->retailCrmService->getProducts(['ids' => [$externalId]]);
                    if ($response->success && !empty($response->products)) {
                         foreach ($response->products as $product) {
                            // Проверяем совпадение по ID товара
                            if ((string)$product->id === $externalId || (string)($product->externalId ?? '') === $externalId) {
                                // ВАЖНО: Для заказа нам нужен ID торгового предложения (Offer ID), а не товара.
                                // Если у товара есть предложения, берем первое (так как у нас нет информации о варианте)
                                if (!empty($product->offers)) {
                                    return (string)$product->offers[0]->id;
                                }
                                return (string)$product->id;
                            }
                        }
                    }
                    break;

                case 'customer':
                    $response = $this->retailCrmService->getCustomers(['ids' => [$externalId]]);
                     if ($response->success && !empty($response->customers)) {
                         foreach ($response->customers as $customer) {
                            if ((string)$customer->id === $externalId || (string)($customer->externalId ?? '') === $externalId) {
                                return (string)$customer->id;
                            }
                        }
                    }
                    break;
            }
        } catch (\Exception $e) {
            Log::warning("Ошибка запроса к RetailCRM при поиске {$entityType}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Создает заглушку сущности в RetailCRM, если она не найдена.
     */
    protected function createRetailCrmEntityStub(string $entityType, string $externalId, ?string $shopCode): ?string
    {
         if (!isset($this->retailCrmService)) {
            return null;
        }

        try {
            $site = ShopCodeResolver::resolve($shopCode);

            switch ($entityType) {
                case 'category':
                    // Создаем категорию-заглушку
                    $data = [
                        'name' => "Category {$externalId} (Recovered)",
                        'externalId' => $externalId,
                        'site' => $site,
                        'active' => true,
                    ];
                    // Пытаемся создать (метод createProductGroup возвращает response)
                    $response = $this->retailCrmService->createProductGroup($data);
                    return $response->success ? (string)$response->id : null;

                case 'customer':
                    // Создаем клиента-заглушку
                    $data = [
                        'firstName' => "Customer {$externalId}",
                        'lastName' => "(Recovered)",
                        'externalId' => $externalId,
                        'site' => $site,
                    ];
                    $response = $this->retailCrmService->createCustomer($data);
                     return $response->success ? (string)$response->id : null;
            }
        } catch (\Exception $e) {
             Log::error("Не удалось создать заглушку для {$entityType} {$externalId}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Определяет, нужно ли синхронизировать сущность в RetailCRM.
     *
     * Логика:
     * - Если source === retailcrm → НЕТ (сущность УЖЕ в RetailCRM, не синхронизируем обратно)
     * - Если source === currentSource → ДА (наша сущность изменена в другом месте, синхронизируем)
     * - Если source === другой источник → ДА (внешняя сущность, синхронизируем)
     *
     * @param string $source Источник-владелец сущности
     * @param string $retailcrmSource Источник RetailCRM из конфига
     * @return bool
     */
    protected function shouldSyncToRetailCRM(string $source, string $retailcrmSource): bool
    {
        // Сущности из RetailCRM не синхронизируем обратно
        if ($source === $retailcrmSource) {
            return false;
        }

        // Все остальные сущности синхронизируем:
        // - Наши сущности (source=retailbridge) изменённые в другом месте (event_source=admin)
        // - Внешние сущности (source=bagisto, warehouse, etc.)
        return true;
    }
}
