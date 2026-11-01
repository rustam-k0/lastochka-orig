<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AdminSetting;
use App\Services\ModuleConfigService;

/**
 * Включение/выключение флага delivery_price_ranges («Диапазоны стоимости
 * доставки», MID-280). tearDown в трейте намеренно не объявлен (конфликтовал бы
 * с другими Interacts-трейтами): тестовый класс сам зовёт
 * ModuleConfigService::invalidateCache() в своём tearDown().
 */
trait InteractsWithDeliveryPriceRanges
{
    protected function setDeliveryPriceRangesModule(bool $enabled): void
    {
        $raw = AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY);
        $modules = $raw ? (json_decode($raw, true) ?: []) : [];
        $modules[ModuleConfigService::DELIVERY_PRICE_RANGES_KEY] = [
            'label' => 'Диапазоны стоимости доставки',
            'enabled' => $enabled,
        ];

        AdminSetting::setValue(
            ModuleConfigService::PROJECT_MODULES_KEY,
            json_encode($modules, JSON_UNESCAPED_UNICODE),
            'json'
        );
        ModuleConfigService::invalidateCache();
    }
}
