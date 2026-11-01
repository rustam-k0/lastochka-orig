<?php

namespace App\GraphQL\Mutations\AdminSetting;

use App\Models\AdminSetting;
use App\Services\CustomCheckboxService;
use Illuminate\Support\Facades\Schema;

class SaveEntityCheckboxes
{
    public function __invoke($_, array $args): AdminSetting
    {
        $config = $args['config'];

        $decoded = json_decode($config, true);
        if ($decoded === null) {
            throw new \InvalidArgumentException('Некорректный JSON конфигурации');
        }

        $defaults = CustomCheckboxService::defaultCheckboxConfig();

        foreach ($decoded as $entity => $checkboxes) {
            if (!array_key_exists($entity, CustomCheckboxService::ENTITY_TABLES)) {
                throw new \InvalidArgumentException("Неизвестная сущность: {$entity}");
            }
            if (!is_array($checkboxes)) {
                throw new \InvalidArgumentException("Чекбоксы для {$entity} должны быть массивом");
            }

            $builtinFields = array_column($defaults[$entity], 'field');
            $tableColumns = Schema::getColumnListing(CustomCheckboxService::ENTITY_TABLES[$entity]);
            $seenFields = [];

            foreach ($checkboxes as $checkbox) {
                if (!isset($checkbox['field'], $checkbox['label'], $checkbox['enabled'])) {
                    throw new \InvalidArgumentException('Каждый чекбокс должен содержать field, label, enabled');
                }

                $field = $checkbox['field'];

                if (in_array($field, $seenFields, true)) {
                    throw new \InvalidArgumentException("Дубликат чекбокса «{$field}» у {$entity}");
                }
                $seenFields[] = $field;

                if (!($checkbox['custom'] ?? false)) {
                    continue;
                }

                // Валидация только custom-элементов: слаг, коллизии с builtin и колонками
                if (!is_string($field) || !preg_match(CustomCheckboxService::FIELD_PATTERN, $field)) {
                    throw new \InvalidArgumentException(
                        "Слаг «{$field}» некорректен: латиница в нижнем регистре, цифры и _, от 2 до 50 символов, начинается с буквы"
                    );
                }
                if (trim((string) $checkbox['label']) === '') {
                    throw new \InvalidArgumentException("У чекбокса «{$field}» пустое название");
                }
                if (in_array($field, $builtinFields, true) || in_array($field, $tableColumns, true)) {
                    throw new \InvalidArgumentException(
                        "Слаг «{$field}» зарезервирован системным полем {$entity}"
                    );
                }
            }
        }

        return AdminSetting::setValue('entity_checkboxes', $config, 'json');
    }
}
