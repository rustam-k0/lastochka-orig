<?php

namespace App\Services\Converters\Concerns;

use App\Support\ShopCodeResolver;

/**
 * Trait для централизованной резолюции shop_code в конвертерах.
 *
 * Делегирует логику в ShopCodeResolver для консистентности
 * с другими частями системы (Consumer handlers, etc.)
 *
 * @see App\Support\ShopCodeResolver
 * @see docs/tz/001-multivendor-shop-code.md
 */
trait ResolvesShopCode
{
    /**
     * Резолвит shop_code с учетом fallback цепочки.
     *
     * @param string|null $shopCode Shop code из входящих данных
     * @return string Итоговый shop_code
     */
    protected function resolveShopCode(?string $shopCode): string
    {
        return ShopCodeResolver::resolve($shopCode);
    }

    /**
     * Извлекает shop_code из данных события/RetailCRM.
     *
     * @param array $data Данные с возможными полями shop_code/site
     * @return string Резолвленный shop_code
     */
    protected function extractShopCode(array $data): string
    {
        return ShopCodeResolver::extractFromData($data);
    }
}
