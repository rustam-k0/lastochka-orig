<?php

namespace App\Services\Consumers;

use App\Models\System\EventSyncMetadata;
use App\Services\Converters\CustomerConverter;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Обработчик событий клиентов (customer.*).
 */
class CustomerConsumerHandler extends BaseConsumerHandler
{
    protected string $entityType = 'customer';

    protected CustomerConverter $converter;
    protected RetailCRMService $retailCrmService;

    public function __construct(CustomerConverter $converter, RetailCRMService $retailCrmService)
    {
        $this->converter = $converter;
        $this->retailCrmService = $retailCrmService;
    }

    /**
     * Валидация структуры клиента.
     */
    protected function validateStructure(array $data): bool
    {
        // Минимальные требования - должен быть хотя бы телефон или email
        return parent::validateStructure($data)
            && (isset($data['phone']) || isset($data['email']));
    }

    /**
     * Синхронизирует клиента с RetailCRM.
     */
    protected function syncToRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?string {
        try {
            // Конвертируем данные в формат RetailCRM
            $customerData = $this->converter->toRetailCRM($data);
            $customerData['site'] = $data['shop_code'] ?? config('retailcrm.shop_code', 'default');

            // Если клиент уже существует в RetailCRM (есть entity_id), обновляем
            if ($metadata->entity_id) {
                $response = $this->retailCrmService->updateCustomer(
                    $metadata->entity_id,
                    $customerData,
                    'id'
                );

                if ($response && isset($response->id)) {
                    return (string)$response->id;
                }

                Log::error("Не удалось обновить клиента в RetailCRM", [
                    'customer_id' => $metadata->entity_id,
                    'external_id' => $data['id'],
                ]);

                return null;
            }

            // ВАЖНО: Сначала ищем существующего клиента по телефону/email для предотвращения дублей
            $existingCustomerId = $this->findExistingCustomer($data);
            if ($existingCustomerId) {
                Log::info("Клиент найден по телефону/email в RetailCRM - предотвращено дублирование", [
                    'external_id' => $data['id'],
                    'retail_crm_id' => $existingCustomerId,
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                ]);

                return $existingCustomerId;
            }

            // Только если не найден - создаём нового клиента
            $response = $this->retailCrmService->createCustomer($customerData);

            if ($response && isset($response->id)) {
                Log::info("Создан новый клиент в RetailCRM", [
                    'external_id' => $data['id'],
                    'retail_crm_id' => $response->id,
                ]);
                return (string)$response->id;
            }

            Log::error("Не удалось создать клиента в RetailCRM", [
                'external_id' => $data['id'],
                'response' => $response,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("Ошибка синхронизации клиента с RetailCRM: {$e->getMessage()}", [
                'exception' => $e,
                'external_id' => $data['id'],
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Ищет существующего клиента в RetailCRM по телефону или email.
     */
    protected function findExistingCustomer(array $data): ?string
    {
        try {
            // 1. Сначала ищем по телефону (приоритет, т.к. более уникальный идентификатор)
            if (!empty($data['phone'])) {
                // Фильтр 'name' в RetailCRM ищет по ФИО или телефону
                // Передаём телефон как есть без нормализации, т.к. RetailCRM делает fuzzy match
                // Важно: не передаём limit, т.к. API требует limit >= 20
                $response = $this->retailCrmService->getCustomers(['name' => $data['phone']]);

                if ($response && isset($response->customers) && count($response->customers) > 0) {
                    return (string)$response->customers[0]->id;
                }
            }

            // 2. Если не найден по телефону - ищем по email
            if (!empty($data['email'])) {
                // Важно: не передаём limit, т.к. API требует limit >= 20
                $response = $this->retailCrmService->getCustomers(['email' => $data['email']]);

                if ($response && isset($response->customers) && count($response->customers) > 0) {
                    return (string)$response->customers[0]->id;
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error("Ошибка поиска существующего клиента: {$e->getMessage()}", [
                'exception' => $e,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * Нормализует номер телефона для поиска в RetailCRM.
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
