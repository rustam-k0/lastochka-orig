<?php

namespace App\GraphQL\Mutations\AdminSetting;

use App\Models\AdminSetting;

class SaveEntityFields
{
    private const ALLOWED_ENTITIES = ['Shop', 'Category'];

    private const ALWAYS_VISIBLE = [
        'Shop' => ['title', 'code'],
    ];

    public function __invoke($_, array $args): AdminSetting
    {
        $config = $args['config'];

        $decoded = json_decode($config, true);
        if ($decoded === null) {
            throw new \InvalidArgumentException('Некорректный JSON конфигурации');
        }

        foreach ($decoded as $entity => $fields) {
            if (!in_array($entity, self::ALLOWED_ENTITIES, true)) {
                throw new \InvalidArgumentException("Неизвестная сущность: {$entity}");
            }
            if (!is_array($fields)) {
                throw new \InvalidArgumentException("Поля для {$entity} должны быть массивом");
            }
            $alwaysVisible = self::ALWAYS_VISIBLE[$entity] ?? [];
            foreach ($fields as $row) {
                if (!isset($row['field'], $row['label'], $row['enabled'])) {
                    throw new \InvalidArgumentException('Каждое поле должно содержать field, label, enabled');
                }
                if (in_array($row['field'], $alwaysVisible, true)) {
                    throw new \InvalidArgumentException("Поле {$row['field']} обязательно и не может быть скрыто");
                }
            }
        }

        return AdminSetting::setValue('entity_fields', $config, 'json');
    }
}
