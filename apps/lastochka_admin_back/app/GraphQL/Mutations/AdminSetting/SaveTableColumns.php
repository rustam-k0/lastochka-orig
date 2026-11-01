<?php

namespace App\GraphQL\Mutations\AdminSetting;

use App\Models\AdminSetting;
use App\Services\TableColumnsService;
use GraphQL\Error\Error;

class SaveTableColumns
{
    /**
     * Ошибки валидации бросаем через GraphQL\Error\Error: он ClientAware, поэтому
     * админка показывает текст причины, а не «Internal server error». Обычный
     * InvalidArgumentException Lighthouse считает внутренним сбоем — отдавал 500
     * и засорял трекер ошибок штатными отказами валидации.
     */
    public function __invoke($_, array $args): AdminSetting
    {
        $config = $args['config'];

        $decoded = json_decode($config, true);
        if (!is_array($decoded)) {
            throw new Error('Некорректный JSON конфигурации');
        }

        foreach ($decoded as $table => $columns) {
            if (!in_array($table, TableColumnsService::tables(), true)) {
                throw new Error("Неизвестная таблица: {$table}");
            }
            if (!is_array($columns)) {
                throw new Error("Колонки для {$table} должны быть массивом");
            }

            $known = TableColumnsService::knownFields($table);
            $seen = [];
            foreach ($columns as $row) {
                if (!is_array($row) || !isset($row['field'], $row['label']) || !array_key_exists('enabled', $row)) {
                    throw new Error('Каждая колонка должна содержать field, label, enabled');
                }
                $field = $row['field'];
                if (!in_array($field, $known, true)) {
                    throw new Error("Неизвестная колонка {$table}.{$field}");
                }
                if (isset($seen[$field])) {
                    throw new Error("Колонка {$table}.{$field} указана дважды");
                }
                $seen[$field] = true;

                if (!$row['enabled'] && TableColumnsService::isSystem($table, $field)) {
                    throw new Error("Колонка {$table}.{$field} служебная и не может быть скрыта");
                }
            }
        }

        return AdminSetting::setValue(TableColumnsService::SETTING_KEY, $config, 'json');
    }
}
