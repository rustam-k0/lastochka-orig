<?php

namespace App\Support;

use App\Services\RetailCRM\RetailCRMService;

/**
 * Хелпер для централизованной резолюции shop_code.
 *
 * Fallback приоритет:
 * 1. Переданный $shopCode (из входящего события или данных)
 * 2. config('retailcrm.shop_code')
 * 3. RetailCRMService::getMainSite() — первый активный site из API
 * 4. 'default' (hardcoded fallback)
 *
 * @see docs/tz/001-multivendor-shop-code.md
 */
class ShopCodeResolver
{
    /**
     * Резолвит shop_code с учетом fallback цепочки.
     *
     * @param string|null $shopCode Shop code из входящих данных
     * @return string Итоговый shop_code
     */
    public static function resolve(?string $shopCode): string
    {
        // 1. Если передан валидный shop_code — используем его
        if (!empty($shopCode) && $shopCode !== 'default') {
            return $shopCode;
        }

        // 2. Fallback на конфиг
        $configShopCode = config('retailcrm.shop_code');
        if (!empty($configShopCode) && $configShopCode !== 'default') {
            return $configShopCode;
        }

        // 3. Fallback на первый активный site из RetailCRM API (кэшируется на 1 час)
        try {
            $mainSite = app(RetailCRMService::class)->getMainSite();
            if ($mainSite) {
                return $mainSite;
            }
        } catch (\Throwable $e) {
            // API недоступен — продолжаем к hardcoded fallback
        }

        // 4. Hardcoded fallback
        return 'default';
    }

    /**
     * Извлекает shop_code из массива данных события/RetailCRM.
     *
     * @param array $data Данные с возможными полями shop_code/site
     * @return string Резолвленный shop_code
     */
    public static function extractFromData(array $data): string
    {
        // Приоритет: shop_code > site > fallback
        $shopCode = $data['shop_code'] ?? $data['site'] ?? null;

        return self::resolve($shopCode);
    }
}
