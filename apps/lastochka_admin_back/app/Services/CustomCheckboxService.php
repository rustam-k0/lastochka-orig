<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Динамические чекбоксы контент-сущностей.
 *
 * Определения живут в admin_settings.entity_checkboxes (JSON-блоб):
 * builtin-элементы {field, label, enabled} управляют захардкоженными колонками,
 * custom-элементы {field, label, enabled, custom: true, filterable?: bool} —
 * создаются из админки, их значения хранятся в JSON-колонке custom_checkboxes.
 */
class CustomCheckboxService
{
    public const FIELD_PATTERN = '/^[a-z][a-z0-9_]{1,49}$/';

    public const ENTITY_TABLES = [
        'Product' => 'products',
        'Category' => 'categories',
        'MerchantProductGroup' => 'merchant_product_groups',
        'Story' => 'stories',
        'Brand' => 'brands',
        'MerchantBanner' => 'merchant_banners',
        'MerchantSale' => 'merchant_sales',
        'Shop' => 'shops',
    ];

    /**
     * Нормализовать аргумент custom_checkboxes в args GraphQL-мутации.
     *
     * Ключ отсутствует — args не трогаем (partial update не затирает значения).
     * null или битый JSON — ключ убирается (fail-safe, значение не меняется).
     * Валидная map — неизвестные поля отбрасываются, значения кастятся к bool,
     * результат кладётся обратно JSON-строкой (пустая map = '{}', не '[]').
     */
    public static function sanitizeArgs(string $entity, array $args): array
    {
        if (!array_key_exists('custom_checkboxes', $args)) {
            return $args;
        }

        $raw = $args['custom_checkboxes'];
        $decoded = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);

        if (!is_array($decoded)) {
            unset($args['custom_checkboxes']);

            return $args;
        }

        $allowed = self::customFields($entity);
        $clean = [];
        foreach ($decoded as $field => $value) {
            if (in_array($field, $allowed, true)) {
                $clean[$field] = (bool) $value;
            }
        }

        $args['custom_checkboxes'] = json_encode((object) $clean, JSON_UNESCAPED_UNICODE);

        return $args;
    }

    /**
     * Слаги custom-чекбоксов сущности из сохранённого блоба настроек.
     *
     * @return array<int, string>
     */
    public static function customFields(string $entity): array
    {
        $value = AdminSetting::getValue('entity_checkboxes');
        $config = $value ? json_decode($value, true) : null;

        if (!is_array($config) || !is_array($config[$entity] ?? null)) {
            return [];
        }

        $fields = [];
        foreach ($config[$entity] as $item) {
            if (is_array($item)
                && ($item['custom'] ?? false)
                && is_string($item['field'] ?? null)
                && preg_match(self::FIELD_PATTERN, $item['field'])
            ) {
                $fields[] = $item['field'];
            }
        }

        return $fields;
    }

    /**
     * Builtin-дефолты чекбоксов (единый источник для запроса entityCheckboxes
     * и валидации SaveEntityCheckboxes). У Brand/MerchantBanner/MerchantSale/Shop
     * builtin-чекбоксов нет — только custom.
     */
    public static function defaultCheckboxConfig(): array
    {
        return [
            'Product' => [
                ['field' => 'is_culinary', 'label' => 'Кулинария', 'enabled' => true],
                ['field' => 'sugar_free', 'label' => 'Без сахара', 'enabled' => true],
                ['field' => 'on_search_page', 'label' => 'Выводить на странице поиска', 'enabled' => true],
                ['field' => 'served_warm', 'label' => 'Подаётся тёплым', 'enabled' => true],
                ['field' => 'pickup_only', 'label' => 'Только самовывоз', 'enabled' => true],
                ['field' => 'adult_only', 'label' => 'Товар 18+', 'enabled' => true],
                ['field' => 'is_marked', 'label' => 'Маркируемый товар (Честный знак)', 'enabled' => true],
                ['field' => 'is_new', 'label' => 'Новинка', 'enabled' => true],
                ['field' => 'is_hurry_up', 'label' => 'Успей купить', 'enabled' => true],
            ],
            'Category' => [
                ['field' => 'important', 'label' => 'Важные категории', 'enabled' => true],
                ['field' => 'is_cooking', 'label' => 'Кулинария', 'enabled' => true],
                ['field' => 'is_perishable', 'label' => 'Скоропортящийся / заморозка', 'enabled' => true],
                ['field' => 'show_on_main', 'label' => 'Показывать на главной странице', 'enabled' => true],
            ],
            'MerchantProductGroup' => [
                ['field' => 'show_on_main', 'label' => 'Показывать на главной', 'enabled' => true],
                ['field' => 'show_on_merchant_page', 'label' => 'Показывать на странице магазина', 'enabled' => true],
                ['field' => 'show_in_merchant_catalog', 'label' => 'Показывать в каталоге магазина', 'enabled' => true],
                ['field' => 'show_on_search', 'label' => 'Выводить на странице поиска', 'enabled' => true],
                ['field' => 'is_recommended', 'label' => 'Рекомендуем', 'enabled' => true],
                ['field' => 'is_cart_page', 'label' => 'Выводить на странице корзины', 'enabled' => true],
            ],
            'Story' => [
                ['field' => 'is_quick_category', 'label' => 'Быстрая категория', 'enabled' => true],
            ],
            'Brand' => [],
            'MerchantBanner' => [],
            'MerchantSale' => [],
            'Shop' => [],
        ];
    }
}
