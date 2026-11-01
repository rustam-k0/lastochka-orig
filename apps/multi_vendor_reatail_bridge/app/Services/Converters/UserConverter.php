<?php

namespace App\Services\Converters;

class UserConverter extends BaseConverter
{
    public function __construct(array $config = null)
    {
        $this->retailcrmSource = $config['source'] ?? config('retailcrm.source', 'retailcrm');
    }

    public function fromRetailCRM(array $data, $metadata = null): array
    {
        // Custom dictionary element (collectors) or Courier entity (couriers)

        // Determine source/type
        // Couriers from API usually have firstName, lastName, patronymic.
        // Dictionary elements have name (string) and code.

        $role = $data['__role'] ?? 'collector';

        $id = (string) ($data['code'] ?? $data['id'] ?? uniqid());

        if (isset($data['firstName']) || isset($data['lastName'])) {
            // It looks like a Courier object (or User object)
            $name = $data['firstName'] ?? '';
            $surname = $data['lastName'] ?? '';
            $patronymic = $data['patronymic'] ?? '';
        } else {
            // It looks like a Dictionary element (Collector)
            $fullName = $data['name'] ?? '';
            $nameParts = explode(' ', $fullName);
            $surname = $nameParts[0] ?? '';
            $name = $nameParts[1] ?? '';
            $patronymic = $nameParts[2] ?? '';

            if (count($nameParts) === 1) {
                $surname = '';
                $name = $fullName;
                $patronymic = '';
            }
        }

        // Determine source and ID from metadata if available
        $source = $this->retailcrmSource;
        if ($metadata) {
            $source = $metadata->source;
            $id = $metadata->external_id;
        }

        return [
            'id' => $id,
            'shop_code' => $this->extractShopCode($data),
            'source' => $source,
            'active' => isset($data['active']) ? (bool)$data['active'] : true,
            'role' => $role,
            'name' => $name,
            'surname' => $surname,
            'patronymic' => $patronymic,
            'phone' => $data['phone']['number'] ?? '', // Couriers might have phone
            'email' => $data['email'] ?? '',
            'birthday' => null,
            'gender' => null,
            'created_at' => null,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Конвертирует данные сотрудника в формат RetailCRM.
     * 
     * Логика зависит от роли:
     * - courier: формат для API курьеров
     * - collector: формат для кастомного справочника
     */
    public function toRetailCRM(array $data): array
    {
        $role = $data['role'] ?? 'collector';
        
        if ($role === 'courier') {
            return $this->toCourierFormat($data);
        } else {
            return $this->toCollectorFormat($data);
        }
    }

    /**
     * Формат для курьера (справочник курьеров).
     */
    protected function toCourierFormat(array $data): array
    {
        $result = [
            'firstName' => $data['name'] ?? '',
            'lastName' => $data['surname'] ?? '',
            'active' => $data['active'] ?? true,
        ];

        if (!empty($data['patronymic'])) {
            $result['patronymic'] = $data['patronymic'];
        }

        if (!empty($data['phone'])) {
            $result['phone'] = [
                'number' => $data['phone'],
            ];
        }

        if (!empty($data['email'])) {
            $result['email'] = $data['email'];
        }

        return $result;
    }

    /**
     * Формат для сборщика (кастомный справочник).
     */
    protected function toCollectorFormat(array $data): array
    {
        // Формируем полное имя
        $nameParts = array_filter([
            $data['surname'] ?? '',
            $data['name'] ?? '',
            $data['patronymic'] ?? '',
        ]);
        
        $fullName = implode(' ', $nameParts);

        $result = [
            'code' => (string)($data['id'] ?? uniqid()),
            'name' => $fullName ?: 'Без имени',
        ];

        // Дополнительные поля справочника (если поддерживается)
        if (!empty($data['phone'])) {
            $result['description'] = $data['phone']; // Или можно использовать customFields
        }

        return $result;
    }
}
