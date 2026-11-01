<?php

namespace App\Services\Converters;

class CustomerConverter extends BaseConverter
{
    public function __construct(array $config = null)
    {
        $this->retailcrmSource = $config['source'] ?? config('retailcrm.source', 'retailcrm');
    }

    /**
     * Конвертирует данные из формата брокера сообщений в формат RetailCRM.
     *
     * @param array $data
     * @return array
     */
    public function toRetailCRM(array $data): array
    {
        $result = [
            'externalId' => $data['id'],
            'firstName' => $data['name'] ?? '',
            'lastName' => $data['surname'] ?? '',
            'patronymic' => $data['patronymic'] ?? '',
            'phones' => [],
            'site' => $this->resolveShopCode($data['shop_code'] ?? null),
        ];
        
        // Добавляем телефон, если он есть
        if (!empty($data['phone'])) {
            $result['phones'][] = [
                'number' => $data['phone'],
            ];
        }
        
        // Добавляем email, если он есть
        if (!empty($data['email'])) {
            $result['email'] = $data['email'];
        }
        
        // Добавляем день рождения, если он есть
        if (!empty($data['birthday'])) {
            $result['birthday'] = $data['birthday'];
        }
        
        // Добавляем пол, если он есть
        if (!empty($data['gender'])) {
            $result['sex'] = $data['gender'] === 'male' ? 'male' : 'female';
        }
        
        return $result;
    }
    
    /**
     * Конвертирует данные из формата RetailCRM в формат брокера сообщений.
     *
     * @param array $data
     * @param \App\Models\System\EventSyncMetadata|null $metadata
     * @return array
     */
    public function fromRetailCRM(array $data, $metadata = null): array
    {
        $phone = '';
        if (!empty($data['phones']) && is_array($data['phones'])) {
            // Берём первый телефон
            $firstPhone = reset($data['phones']);
            $phone = $firstPhone['number'] ?? '';
        }

        // Handle gender mapping
        $gender = null;
        if (!empty($data['sex'])) {
            $gender = $data['sex'];
        } elseif (!empty($data['presumableSex'])) {
            $gender = $data['presumableSex'];
        }

        // Determine source and ID from metadata if available
        $id = (string) ($data['id'] ?? uniqid());
        $source = $this->retailcrmSource;

        if ($metadata) {
            $source = $metadata->source;
            $id = $metadata->external_id;
        }

        return [
            'id' => $id,
            'externalId' => $data['externalId'] ?? ($data['id'] ?? null),
            'shop_code' => $this->extractShopCode($data),
            'source' => $source,
            'name' => $data['firstName'] ?? '',
            'surname' => $data['lastName'] ?? '',
            'patronymic' => $data['patronymic'] ?? '',
            'phone' => $phone,
            'email' => $data['email'] ?? '',
            'birthday' => $data['birthday'] ?? null,
            'gender' => $gender,
            'created_at' => $data['createdAt'] ?? null,
            'updated_at' => $data['updatedAt'] ?? null,
        ];
    }
}
