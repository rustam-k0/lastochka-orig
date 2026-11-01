<?php

namespace App\Services\Consumers;

use App\Models\System\EventSyncMetadata;
use App\Services\Converters\UserConverter;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Обработчик событий сотрудников (user.*).
 * 
 * Логика ветвления по роли:
 * - courier: создает/обновляет курьера в справочнике курьеров
 * - collector: создает/обновляет элемент в кастомном справочнике 'collectors'
 */
class UserConsumerHandler extends BaseConsumerHandler
{
    protected string $entityType = 'user';

    protected UserConverter $converter;
    protected RetailCRMService $retailCrmService;
    
    /**
     * Код кастомного справочника для сборщиков.
     */
    protected string $collectorsDictionaryCode = 'collectors';

    public function __construct(UserConverter $converter, RetailCRMService $retailCrmService)
    {
        $this->converter = $converter;
        $this->retailCrmService = $retailCrmService;
        
        // Получаем код справочника из конфига
        $this->collectorsDictionaryCode = config('retailcrm.dictionaries.collectors', 'collectors');
    }

    /**
     * Валидация структуры сотрудника.
     */
    protected function validateStructure(array $data): bool
    {
        return parent::validateStructure($data)
            && isset($data['role']);
    }

    /**
     * Синхронизирует сотрудника с RetailCRM.
     */
    protected function syncToRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?string {
        $role = $data['role'] ?? null;

        if ($role === 'courier') {
            return $this->syncCourier($data, $metadata);
        }
        
        if ($role === 'collector') {
            return $this->syncCollector($data, $metadata);
        }

        Log::info("Пропускаем пользователя с неподдерживаемой ролью", [
            'external_id' => $data['id'] ?? null,
            'role' => $role,
        ]);

        return null;
    }

    /**
     * Синхронизирует курьера.
     */
    protected function syncCourier(array $data, EventSyncMetadata $metadata): ?string
    {
        try {
            // Конвертируем данные в формат курьера
            $courierData = $this->converter->toRetailCRM($data);

            // Если курьер уже существует в RetailCRM (есть entity_id), обновляем
            if ($metadata->entity_id) {
                $response = $this->retailCrmService->updateCourier(
                    (int)$metadata->entity_id,
                    $courierData
                );

                if ($response && $response->isSuccessful()) {
                    return (string)$metadata->entity_id;
                }

                Log::error("Не удалось обновить курьера в RetailCRM", [
                    'courier_id' => $metadata->entity_id,
                    'external_id' => $data['id'],
                ]);

                return null;
            }

            // Создаем нового курьера
            $response = $this->retailCrmService->createCourier($courierData);

            if ($response && isset($response->id)) {
                return (string)$response->id;
            }

            Log::error("Не удалось создать курьера в RetailCRM", [
                'external_id' => $data['id'],
                'response' => $response,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("Ошибка синхронизации курьера с RetailCRM: {$e->getMessage()}", [
                'exception' => $e,
                'external_id' => $data['id'],
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Синхронизирует сборщика (кастомный справочник).
     */
    protected function syncCollector(array $data, EventSyncMetadata $metadata): ?string
    {
        try {
            // Конвертируем данные в формат элемента справочника
            $collectorData = $this->converter->toRetailCRM($data);
            $collectorCode = $collectorData['code'];

            // Проверяем, существует ли справочник collectors
            $this->ensureCollectorsDictionary();

            // Если сборщик уже существует в RetailCRM (есть entity_id), обновляем
            // entity_id здесь это code элемента справочника
            if ($metadata->entity_id) {
                $response = $this->retailCrmService->updateDictionaryElement(
                    $this->collectorsDictionaryCode,
                    $metadata->entity_id,
                    $collectorData
                );

                if ($response && isset($response->success) && $response->success) {
                    return $metadata->entity_id; // Код элемента не меняется
                }

                Log::error("Не удалось обновить сборщика в RetailCRM", [
                    'collector_code' => $metadata->entity_id,
                    'external_id' => $data['id'],
                ]);

                return null;
            }

            // Создаем нового сборщика
            $response = $this->retailCrmService->createDictionaryElement(
                $this->collectorsDictionaryCode,
                $collectorData
            );

            if ($response && isset($response->success) && $response->success) {
                return $collectorCode; // Возвращаем code элемента как entity_id
            }

            Log::error("Не удалось создать сборщика в RetailCRM", [
                'external_id' => $data['id'],
                'response' => $response,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("Ошибка синхронизации сборщика с RetailCRM: {$e->getMessage()}", [
                'exception' => $e,
                'external_id' => $data['id'],
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Проверяет существование справочника collectors и создает его при необходимости.
     */
    protected function ensureCollectorsDictionary(): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        try {
            // Пытаемся получить справочник
            $response = $this->retailCrmService->getCustomDictionary($this->collectorsDictionaryCode);

            if ($response && isset($response->customDictionary)) {
                $checked = true;
                return;
            }
        } catch (\Exception $e) {
            Log::info("Справочник collectors не найден, создаем...");
        }

        // Создаем справочник
        try {
            $dictionary = [
                'code' => $this->collectorsDictionaryCode,
                'name' => 'Сборщики',
            ];

            $response = $this->retailCrmService->createCustomDictionary($dictionary);

            if ($response && isset($response->success) && $response->success) {
                Log::info("Справочник collectors успешно создан в RetailCRM");
                $checked = true;
            } else {
                Log::error("Не удалось создать справочник collectors", ['response' => $response]);
            }
        } catch (\Exception $e) {
            Log::error("Ошибка создания справочника collectors: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }
}
