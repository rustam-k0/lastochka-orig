<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Настраиваемый состав и порядок колонок таблиц админки.
 *
 * Блоб `admin_settings.table_columns` хранит только порядок и видимость:
 * заголовки колонок живут в коде фронта, поэтому label в ответе всегда берётся
 * из дефолтов (сохранённый игнорируется). Неизвестные поля из блоба
 * отбрасываются, недостающие — дописываются из дефолтов, иначе добавленная в
 * код колонка никогда не появится на стенде с уже сохранёнными настройками
 * (та же логика, что у projectModules/entityCheckboxes).
 */
class TableColumnsService
{
    public const SETTING_KEY = 'table_columns';

    /**
     * Дефолтный состав колонок — совпадает с тем, что таблицы показывают в коде.
     *
     * `system` — служебная колонка (чекбокс выбора, индикатор, кнопка действий,
     * сумма заказа): её можно двигать, но не скрывать.
     *
     * @return array<string, list<array{field: string, label: string, enabled: bool, system: bool}>>
     */
    public static function defaultConfig(): array
    {
        return [
            'products' => [
                self::column('isActive', 'Индикатор статуса', true),
                self::column('select', 'Выбор строк', true),
                self::column('photo', 'Фото'),
                self::column('title', 'Название'),
                self::column('categories', 'Категория'),
                self::column('default_price', 'Стоимость'),
                self::column('remains', 'Остатки'),
                self::column('fixed_stock', 'Фиксированные остатки'),
                self::column('fill_percent', 'Заполненность'),
                self::column('active', 'Активировать'),
            ],
            'orders' => [
                self::column('id', '№ заказа'),
                self::column('created_at', 'Дата'),
                self::column('client', 'Покупатель'),
                self::column('address', 'Адрес'),
                self::column('status_id', 'Статус'),
                self::column('shop', 'Магазин'),
                self::column('type_id', 'Тип'),
                self::column('slot', 'Слот доставки'),
                self::column('payment_type_id', 'Способ оплаты'),
                self::column('payment_status', 'Статус оплаты'),
                self::column('amount', 'Сумма', true),
                self::column('collector', 'Сборщик'),
                self::column('couriers', 'Курьер'),
                self::column('is_heavy', 'Тяжелый'),
                self::column('action', 'Действия', true),
            ],
        ];
    }

    /** Сохранённая конфигурация, слитая с дефолтами. */
    public static function resolveConfig(): array
    {
        $defaults = self::defaultConfig();
        $stored = json_decode((string) AdminSetting::getValue(self::SETTING_KEY), true);

        if (!is_array($stored)) {
            return $defaults;
        }

        $result = [];
        foreach ($defaults as $table => $columns) {
            $result[$table] = self::mergeTable($columns, $stored[$table] ?? null);
        }

        return $result;
    }

    /** @return list<string> */
    public static function knownFields(string $table): array
    {
        return array_column(self::defaultConfig()[$table] ?? [], 'field');
    }

    public static function isSystem(string $table, string $field): bool
    {
        foreach (self::defaultConfig()[$table] ?? [] as $column) {
            if ($column['field'] === $field) {
                return $column['system'];
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return array_keys(self::defaultConfig());
    }

    /**
     * @param list<array{field: string, label: string, enabled: bool, system: bool}> $defaults
     */
    private static function mergeTable(array $defaults, mixed $stored): array
    {
        if (!is_array($stored)) {
            return $defaults;
        }

        $byField = [];
        foreach ($defaults as $column) {
            $byField[$column['field']] = $column;
        }

        $ordered = [];
        $seen = [];
        foreach ($stored as $row) {
            $field = is_array($row) ? ($row['field'] ?? null) : null;
            if (!is_string($field) || !isset($byField[$field]) || isset($seen[$field])) {
                continue;
            }

            $seen[$field] = true;
            $default = $byField[$field];
            $ordered[] = [
                'field' => $field,
                'label' => $default['label'],
                'enabled' => $default['system'] ? true : (bool) ($row['enabled'] ?? true),
                'system' => $default['system'],
            ];
        }

        foreach ($defaults as $column) {
            if (!isset($seen[$column['field']])) {
                $ordered[] = $column;
            }
        }

        return $ordered;
    }

    /** @return array{field: string, label: string, enabled: bool, system: bool} */
    private static function column(string $field, string $label, bool $system = false): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'enabled' => true,
            'system' => $system,
        ];
    }
}
