<?php

namespace App\Services\Converters;

class ShopConverter extends BaseConverter
{
    public function __construct(array $config = null)
    {
        $this->retailcrmSource = $config['source'] ?? config('retailcrm.source', 'retailcrm');
    }

    public function fromRetailCRM(array $data, $metadata = null): array
    {
        // Determine source and ID from metadata if available
        $id = (string) ($data['code'] ?? $data['id'] ?? uniqid());
        $source = $this->retailcrmSource;

        if ($metadata) {
            $source = $metadata->source;
            $id = $metadata->external_id;
        }

        // Для ShopConverter shop_code = код самого магазина (site) из RetailCRM
        // При этом данные магазина конвертируются из того же самого site
        $shopCode = $data['code'] ?? $this->extractShopCode($data);

        return [
            'id' => $id,
            'shop_code' => $shopCode,
            'source' => $source,
            'title' => $data['name'] ?? '',
            'active' => isset($data['active']) ? (bool)$data['active'] : true,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Конвертирует данные магазина из формата RabbitMQ в формат RetailCRM API.
     *
     * @param array $data Данные магазина из RabbitMQ
     * @return array Данные для POST /api/v5/reference/sites/edit
     */
    public function toRetailCRM(array $data): array
    {
        $shopCode = $data['shop_code'] ?? $data['id'] ?? '';
        
        // Валидация shop_code для RetailCRM: только [a-zA-Z0-9_-], max 50 chars
        $shopCode = $this->sanitizeShopCode($shopCode);
        
        $result = [
            'code' => $shopCode,
            'name' => $data['title'] ?? $shopCode, // Fallback на shop_code если нет title
            'url' => $data['url'] ?? '',
            'countryIso' => $data['country_iso'] ?? config('retailcrm.default_country_iso', 'KZ'),
        ];

        // Опциональные поля
        if (isset($data['catalog_id'])) {
            $result['catalogId'] = $data['catalog_id'];
        }
        
        if (isset($data['ordering'])) {
            $result['ordering'] = (int) $data['ordering'];
        }

        return $result;
    }

    /**
     * Извлекает расширенные поля для хранения в event_sync_metadata.data.
     * Эти поля не поддерживаются RetailCRM sites API напрямую.
     *
     * @param array $data
     * @return array
     */
    public function extractExtendedData(array $data): array
    {
        $extended = [];

        if (isset($data['schedule'])) {
            $extended['schedule'] = $data['schedule'];
        }

        if (isset($data['latitude'])) {
            $extended['latitude'] = (float) $data['latitude'];
        }

        if (isset($data['longitude'])) {
            $extended['longitude'] = (float) $data['longitude'];
        }

        if (isset($data['slot_time'])) {
            $extended['slot_time'] = (int) $data['slot_time'];
        }

        if (isset($data['open_24_7'])) {
            $extended['open_24_7'] = (bool) $data['open_24_7'];
        }

        if (isset($data['active'])) {
            $extended['active'] = (bool) $data['active'];
        }

        return $extended;
    }

    /**
     * Очищает shop_code для соответствия требованиям RetailCRM.
     * Допустимые символы: [a-zA-Z0-9_-], max 50 chars.
     *
     * @param string $shopCode
     * @return string
     */
    protected function sanitizeShopCode(string $shopCode): string
    {
        // Заменяем недопустимые символы на дефис
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '-', $shopCode);
        
        // Убираем множественные дефисы
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        
        // Убираем дефисы в начале и конце
        $sanitized = trim($sanitized, '-');
        
        // Ограничиваем длину до 50 символов
        return substr($sanitized, 0, 50);
    }
}
