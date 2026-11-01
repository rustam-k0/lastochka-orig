<?php

namespace App\Services\Consumers;

use App\Models\System\EventSyncMetadata;
use App\Services\Converters\ShopConverter;
use App\Services\Hash\EntityHashService;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Обработчик событий магазинов (shop.*).
 * 
 * Обрабатывает события:
 * - shop.created: создает site в RetailCRM
 * - shop.updated: обновляет site в RetailCRM
 * - shop.deleted: помечает site в RetailCRM суффиксом "(УДАЛЁН)" в name
 * 
 * Особенности:
 * - Sites в RetailCRM не имеют numeric ID, используется code
 * - reference/sites/edit работает как upsert
 * - Расширенные поля (schedule, coordinates, slot_time) хранятся в metadata.data
 */
class ShopConsumerHandler extends BaseConsumerHandler
{
    protected string $entityType = 'shop';

    protected ShopConverter $converter;
    protected RetailCRMService $retailCrmService;
    protected EntityHashService $hashService;

    public function __construct(
        ShopConverter $converter,
        RetailCRMService $retailCrmService,
        EntityHashService $hashService
    ) {
        $this->converter = $converter;
        $this->retailCrmService = $retailCrmService;
        $this->hashService = $hashService;
    }

    /**
     * Главный метод обработки события.
     * Переопределен для обработки shop.deleted особым образом.
     *
     * @param array $data Данные события из RabbitMQ
     * @param AMQPMessage $message Сырое сообщение RabbitMQ
     * @return bool True если успешно обработано
     */
    public function handle(array $data, AMQPMessage $message): bool
    {
        // Определяем тип события из routing key
        $routingKey = $message->getRoutingKey();
        
        // Для shop.deleted деактивируем site в RetailCRM
        if ($routingKey === 'shop.deleted') {
            return $this->deactivateSite($data);
        }

        return parent::handle($data, $message);
    }

    /**
     * Помечает site в RetailCRM как удалённый.
     * RetailCRM API не поддерживает удаление или деактивацию sites,
     * поэтому добавляем суффикс "(УДАЛЁН)" к имени для идентификации.
     */
    protected function deactivateSite(array $data): bool
    {
        $shopCode = $data['shop_code'] ?? null;

        if (!$shopCode) {
            Log::warning("shop.deleted без shop_code, пропуск", [
                'event_id' => $data['event_id'] ?? 'unknown',
                'id' => $data['id'] ?? 'unknown',
            ]);
            return true;
        }

        $sanitizedCode = $this->converter->toRetailCRM($data)['code'];
        $originalName = $data['title'] ?? $sanitizedCode;
        $deletedName = str_contains($originalName, '(УДАЛЁН)')
            ? $originalName
            : "{$originalName} (УДАЛЁН)";

        Log::info("Пометка site как удалённого в RetailCRM", [
            'shop_code' => $sanitizedCode,
            'new_name' => $deletedName,
            'event_id' => $data['event_id'] ?? 'unknown',
        ]);

        try {
            $success = $this->retailCrmService->editSite([
                'code' => $sanitizedCode,
                'name' => $deletedName,
            ]);

            if ($success) {
                Log::info("Site успешно помечен как удалённый в RetailCRM", ['shop_code' => $sanitizedCode]);
            } else {
                Log::error("Не удалось пометить site как удалённый в RetailCRM", ['shop_code' => $sanitizedCode]);
            }

            return true; // ACK в любом случае
        } catch (\Exception $e) {
            Log::error("Ошибка пометки site как удалённого: {$e->getMessage()}", [
                'shop_code' => $sanitizedCode,
                'exception' => $e,
            ]);
            return true;
        }
    }

    /**
     * Валидация структуры магазина.
     * Обязательные поля: id, shop_code, source
     */
    protected function validateStructure(array $data): bool
    {
        if (!parent::validateStructure($data)) {
            return false;
        }

        // shop_code обязателен
        if (empty($data['shop_code'])) {
            Log::error("Отсутствует обязательное поле shop_code", ['data' => $data]);
            return false;
        }

        // Валидация shop_code: alphanumeric + дефис, max 50 chars
        $shopCode = $data['shop_code'];
        if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $shopCode)) {
            Log::warning("shop_code содержит недопустимые символы, будет очищен", [
                'original_shop_code' => $shopCode,
            ]);
            // Не отклоняем, конвертер очистит
        }

        return true;
    }

    /**
     * Синхронизирует магазин с RetailCRM.
     *
     * @param array $data Данные события
     * @param EventSyncMetadata $metadata Метаданные синхронизации
     * @param AMQPMessage $message Оригинальное AMQP сообщение
     * @return string|null shop_code как entity_id (sites не имеют numeric ID)
     */
    protected function syncToRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?string {
        try {
            // 1. Конвертация данных в формат RetailCRM
            $siteData = $this->converter->toRetailCRM($data);
            $shopCode = $siteData['code'];

            Log::info("Синхронизация магазина с RetailCRM", [
                'shop_code' => $shopCode,
                'external_id' => $data['id'],
                'source' => $data['source'],
            ]);

            // 2. Вызов API: editSite() работает как upsert
            $success = $this->retailCrmService->editSite($siteData);

            if (!$success) {
                Log::error("Не удалось создать/обновить site в RetailCRM", [
                    'shop_code' => $shopCode,
                    'external_id' => $data['id'],
                ]);
                return null;
            }

            // 3. Сохраняем расширенные данные в metadata
            $extendedData = $this->converter->extractExtendedData($data);
            if (!empty($extendedData)) {
                $metadata->data = $extendedData;
            }

            Log::info("Магазин успешно синхронизирован с RetailCRM", [
                'shop_code' => $shopCode,
                'external_id' => $data['id'],
                'has_extended_data' => !empty($extendedData),
            ]);

            // 4. Сохраняем хеш на основе данных из RetailCRM (GET после POST)
            $this->hashService->saveHashAfterSync('shop', $shopCode);

            // 5. Возвращаем shop_code как entity_id (sites не имеют numeric ID)
            return $shopCode;

        } catch (\Exception $e) {
            Log::error("Ошибка синхронизации магазина с RetailCRM: {$e->getMessage()}", [
                'exception' => $e,
                'external_id' => $data['id'] ?? 'unknown',
                'shop_code' => $data['shop_code'] ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
