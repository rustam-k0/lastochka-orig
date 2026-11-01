<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ModuleConfigService
{
    public const PROJECT_MODULES_KEY = 'project_modules';

    public const CONFIGURABLE_PRODUCTS_KEY = 'configurable_products';
    public const CACHE_KEY_CONFIGURABLE_PRODUCTS = 'module_config:admin:configurable_products';

    public const B2B_KEY = 'b2b';
    public const CACHE_KEY_B2B = 'module_config:admin:b2b';

    public const BONUSES_KEY = 'bonuses';
    public const CACHE_KEY_BONUSES = 'module_config:admin:bonuses';

    public const PRODUCT_READY_TIME_KEY = 'product_ready_time';
    public const CACHE_KEY_PRODUCT_READY_TIME = 'module_config:admin:product_ready_time';

    public const HEAVY_ORDERS_KEY = 'heavy_orders';
    public const CACHE_KEY_HEAVY_ORDERS = 'module_config:admin:heavy_orders';

    public const ASSEMBLY_COST_KEY = 'assembly_cost';
    public const CACHE_KEY_ASSEMBLY_COST = 'module_config:admin:assembly_cost';

    public const CATEGORY_1C_NETWORK_KEY = 'category_1c_network_mapping';
    public const CACHE_KEY_CATEGORY_1C_NETWORK_ENABLED = 'module_config:admin:category_1c_network_enabled';
    public const CACHE_KEY_CATEGORY_1C_NETWORK_ANCHOR = 'module_config:admin:category_1c_network_anchor';

    public const ONEC_CARD_MASTER_KEY = 'onec_card_master';
    public const CACHE_KEY_ONEC_CARD_MASTER = 'module_config:admin:onec_card_master';

    public const ONEC_SHOPS_MASTER_KEY = 'onec_shops_master';
    public const CACHE_KEY_ONEC_SHOPS_MASTER = 'module_config:admin:onec_shops_master';

    public const PRICE_TIERS_KEY = 'price_tiers';
    public const CACHE_KEY_PRICE_TIERS = 'module_config:admin:price_tiers';

    public const DELIVERY_PRICE_RANGES_KEY = 'delivery_price_ranges';
    public const CACHE_KEY_DELIVERY_PRICE_RANGES = 'module_config:admin:delivery_price_ranges';

    /**
     * Включены ли вариативные товары.
     * Fail-safe: любые исключения → false.
     */
    public static function isConfigurableProductsEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_CONFIGURABLE_PRODUCTS,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::CONFIGURABLE_PRODUCTS_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read configurable_products flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Включён ли модуль B2B.
     * Fail-safe: любые исключения → false.
     */
    public static function isB2bEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_B2B,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::B2B_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read b2b flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Включён ли модуль «Бонусная система».
     *
     * Дефолт true, если ключа нет в сохранённом блобе: серверный дефолт в
     * AdminSettingQueries::getDefaultModulesConfig() тоже true, и админка видит
     * модуль включённым. Fail-safe на исключении — тоже true, чтобы сбой чтения
     * настроек не блокировал операторам работу с баллами.
     */
    public static function isBonusesEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_BONUSES,
                now()->addMinutes(5),
                fn () => self::readBooleanModuleWithDefault(self::BONUSES_KEY, true)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read bonuses flag, defaulting to true', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Включён ли модуль «Время готовности товаров».
     * Fail-safe: любые исключения → false.
     */
    public static function isProductReadyTimeEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_PRODUCT_READY_TIME,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::PRODUCT_READY_TIME_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read product_ready_time flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Включён ли модуль «Тяжёлые заказы».
     * Fail-safe: любые исключения → false.
     */
    public static function isHeavyOrdersEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_HEAVY_ORDERS,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::HEAVY_ORDERS_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read heavy_orders flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * «1С ведёт карточку товара» (MID-305): события 1С перезаписывают артикул,
     * активность и фото товара. Fail-safe: любые исключения → false.
     */
    public static function isOneCCardMasterEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_ONEC_CARD_MASTER,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::ONEC_CARD_MASTER_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read onec_card_master flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * «1С ведёт магазины и сотрудников» (MID-306): события 1С перезаписывают
     * статус, контакты, график магазина и принимаются сотрудники.
     * Fail-safe: любые исключения → false.
     */
    public static function isOneCShopsMasterEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_ONEC_SHOPS_MASTER,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::ONEC_SHOPS_MASTER_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read onec_shops_master flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * «Цены от количества» (MID-308): лесенка цен товара по магазинам, приём
     * ключа price_tiers из событий, аргумент price_tiers в мутациях, снапшот в
     * позиции заказа. Fail-safe: любые исключения → false (старое поведение).
     */
    public static function isPriceTiersEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_PRICE_TIERS,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::PRICE_TIERS_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read price_tiers flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * «Диапазоны стоимости доставки» (MID-280): лесенка стоимости доставки зоны
     * от суммы заказа. Гейтит применение ступеней в мутации зоны и в событии
     * магазина; на снимок заказа и на курьерку не влияет (она флаг не читает).
     * Отдельного гейта на суперадмина нет (в отличие от price_tiers).
     * Fail-safe: любые исключения → false.
     */
    public static function isDeliveryPriceRangesEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_DELIVERY_PRICE_RANGES,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::DELIVERY_PRICE_RANGES_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read delivery_price_ranges flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Включён ли модуль «Стоимость сборки».
     * Fail-safe: любые исключения → false.
     */
    public static function isAssemblyCostEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_ASSEMBLY_COST,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::ASSEMBLY_COST_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read assembly_cost flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Включён ли сетевой маппинг категорий 1С (единый справочник на всю сеть).
     * Fail-safe: любые исключения → false.
     */
    public static function isCategory1cNetworkMappingEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_CATEGORY_1C_NETWORK_ENABLED,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::CATEGORY_1C_NETWORK_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read category_1c_network_mapping flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Якорный магазин сетевого справочника категорий 1С.
     *
     * null = сетевой режим фактически выключен: модуль выключен, либо
     * anchor_shop_id отсутствует/некорректен (fail-safe — магазин не угадываем).
     * В кеше null представлен как 0 (Cache::remember не различает null и miss).
     */
    public static function getCategory1cNetworkAnchorShopId(): ?int
    {
        try {
            $anchor = (int) Cache::remember(
                self::CACHE_KEY_CATEGORY_1C_NETWORK_ANCHOR,
                now()->addMinutes(5),
                function () {
                    if (!self::readBooleanModule(self::CATEGORY_1C_NETWORK_KEY)) {
                        return 0;
                    }

                    $module = self::readModuleArray(self::CATEGORY_1C_NETWORK_KEY);
                    $value = $module['anchor_shop_id'] ?? null;

                    if (!is_numeric($value) || (int) $value <= 0) {
                        // anchor_shop_id регулярно теряется из блоба при
                        // пересохранении настроек через UI (фронт не знает про
                        // это поле). Модуль включён — значит сеть есть: якорь
                        // резолвим как у network_catalog, по shops.default = 1
                        // (MID-239). Магазин по-прежнему не угадываем: нет
                        // default-магазина — сетевой режим выключен.
                        $defaultShopId = (int) (\App\Models\Shop::query()->where('default', 1)->value('id') ?? 0);

                        if ($defaultShopId > 0) {
                            return $defaultShopId;
                        }

                        Log::warning('category_1c_network_mapping enabled but anchor_shop_id is missing and no default shop found — network mode treated as disabled');

                        return 0;
                    }

                    return (int) $value;
                }
            );

            return $anchor > 0 ? $anchor : null;
        } catch (Throwable $e) {
            Log::warning('Failed to read category_1c_network_mapping anchor, defaulting to null', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function invalidateCache(): void
    {
        Cache::forget(self::CACHE_KEY_CONFIGURABLE_PRODUCTS);
        Cache::forget(self::CACHE_KEY_B2B);
        Cache::forget(self::CACHE_KEY_BONUSES);
        Cache::forget(self::CACHE_KEY_PRODUCT_READY_TIME);
        Cache::forget(self::CACHE_KEY_HEAVY_ORDERS);
        Cache::forget(self::CACHE_KEY_ASSEMBLY_COST);
        Cache::forget(self::CACHE_KEY_CATEGORY_1C_NETWORK_ENABLED);
        Cache::forget(self::CACHE_KEY_CATEGORY_1C_NETWORK_ANCHOR);
        Cache::forget(self::CACHE_KEY_ONEC_CARD_MASTER);
        Cache::forget(self::CACHE_KEY_ONEC_SHOPS_MASTER);
        Cache::forget(self::CACHE_KEY_PRICE_TIERS);
        Cache::forget(self::CACHE_KEY_DELIVERY_PRICE_RANGES);
    }

    /**
     * Читает boolean-модуль из project_modules JSON blob в admin_settings.
     */
    private static function readBooleanModule(string $moduleKey): bool
    {
        $raw = AdminSetting::getValue(self::PROJECT_MODULES_KEY);

        if (!$raw) {
            return false;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return false;
        }

        $module = $decoded[$moduleKey] ?? null;

        if (!is_array($module)) {
            return false;
        }

        return (bool) ($module['enabled'] ?? false);
    }

    /**
     * То же, что readBooleanModule(), но с настраиваемым значением по умолчанию
     * для случая, когда блоб project_modules ещё не содержит этот модуль.
     */
    private static function readBooleanModuleWithDefault(string $moduleKey, bool $default): bool
    {
        $raw = AdminSetting::getValue(self::PROJECT_MODULES_KEY);

        if (!$raw) {
            return $default;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !array_key_exists($moduleKey, $decoded)) {
            return $default;
        }

        $module = $decoded[$moduleKey];

        if (!is_array($module)) {
            return $default;
        }

        return (bool) ($module['enabled'] ?? $default);
    }

    /**
     * Читает объект модуля из project_modules JSON blob как есть.
     */
    private static function readModuleArray(string $moduleKey): ?array
    {
        $raw = AdminSetting::getValue(self::PROJECT_MODULES_KEY);

        if (!$raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $module = $decoded[$moduleKey] ?? null;

        return is_array($module) ? $module : null;
    }
}
