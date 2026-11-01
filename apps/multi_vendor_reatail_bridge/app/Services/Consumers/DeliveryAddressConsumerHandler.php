<?php

namespace App\Services\Consumers;

use App\Models\System\EventSyncMetadata;
use App\Services\Converters\CustomerConverter;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Обработчик событий адресов доставки (customer.address.*).
 * 
 * Обновляет адрес клиента в RetailCRM при получении события.
 */
class DeliveryAddressConsumerHandler extends BaseConsumerHandler
{
    protected string $entityType = 'delivery_address'; // Используем отдельный тип для метаданных адреса

    protected CustomerConverter $converter;
    protected RetailCRMService $retailCrmService;

    public function __construct(CustomerConverter $converter, RetailCRMService $retailCrmService)
    {
        $this->converter = $converter;
        $this->retailCrmService = $retailCrmService;
    }

    /**
     * Валидация структуры адреса.
     */
    protected function validateStructure(array $data): bool
    {
        return parent::validateStructure($data)
            && isset($data['address'])
            && isset($data['client_id']);
    }

    /**
     * Синхронизирует адрес клиента с RetailCRM.
     * По сути, находит клиента и обновляет его адрес.
     */
    protected function syncToRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?string {
        try {
            $clientId = $data['client_id'];
            $clientSource = $data['client_source'] ?? 'unknown';
            
            // 1. Находим ID клиента в RetailCRM
            // Сначала ищем через метаданные
            $customerRetailId = $this->findRetailCrmId('customer', $clientSource, $clientId, $data['shop_code'] ?? null);
            
            // Если не нашли в метаданных, пробуем найти клиента в CRM по externalId (если source совпадает)
            // Но findRetailCrmId уже делает это частично, если source == retailcrm.
            // Если source другой (например, сайт), нам нужно найти клиента, к которому привязать адрес.
            
            if (!$customerRetailId) {
                // Попытка найти клиента в RetailCRM по externalId напрямую (если это поддерживается)
                // RetailCRM позволяет искать по externalId.
                $customers = $this->retailCrmService->getCustomers(['externalId' => $clientId]);
                if ($customers && !empty($customers->customers)) {
                     $customerRetailId = (string)$customers->customers[0]->id;
                }
            }
            
            if (!$customerRetailId) {
                Log::warning("Клиент для адреса доставки не найден в RetailCRM", [
                    'client_id' => $clientId,
                    'address_id' => $data['id']
                ]);
                return null;
            }

            // 2. Формируем данные адреса для RetailCRM
            $addressData = [
                'text' => $data['address'] ?? '',
            ];
            
            if (!empty($data['flat'])) {
                $addressData['flat'] = $data['flat'];
            }
            if (!empty($data['floor'])) {
                $addressData['floor'] = $data['floor'];
            }
            if (!empty($data['entrance'])) {
                $addressData['block'] = $data['entrance']; // Подъезд
            }
            
            // Домофон и другие заметки можно добавить в notes
            $notesParts = [];
            if (!empty($data['intercom'])) {
                $notesParts[] = "Домофон: " . $data['intercom'];
            }
            // Если есть заголовок адреса (например "Дом", "Работа")
            if (!empty($data['title'])) {
                $notesParts[] = "Название: " . $data['title'];
            }
            
            if (!empty($notesParts)) {
                $addressData['notes'] = implode(', ', $notesParts);
            }
            
            // 3. Обновляем клиента
            $customerData = [
                'address' => $addressData
            ];
            
            $response = $this->retailCrmService->updateCustomer($customerRetailId, $customerData, 'id');
            
            if ($response && isset($response->id)) {
                Log::info("Адрес клиента успешно обновлен в RetailCRM", [
                    'customer_id' => $customerRetailId,
                    'address_id' => $data['id']
                ]);
                // Возвращаем ID адреса (или клиента?), чтобы сохранить в метаданных.
                // Метаданные привязаны к delivery_address.external_id (ID адреса).
                // В поле entity_id мы можем сохранить ID клиента, или ID адреса в CRM (но в CRM адреса внутри клиента).
                // Лучше сохранить ID клиента, чтобы знать, к кому привязано.
                // Или просто "1" (flag), так как мы не можем получить ID адреса из RetailCRM (там нет отдельной сущности адреса с ID в ответе updateCustomer).
                return (string)$customerRetailId; 
            }
            
            return null;

        } catch (\Exception $e) {
            Log::error("Ошибка синхронизации адреса доставки: {$e->getMessage()}", [
                'exception' => $e,
                'data' => $data
            ]);
            return null;
        }
    }
}
