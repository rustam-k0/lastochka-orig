<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AdminSetting;
use App\Services\ModuleConfigService;

/**
 * Включение/выключение флага price_tiers («Цены от количества», MID-308).
 * tearDown в трейте намеренно не объявлен (конфликтовал бы с другими
 * Interacts-трейтами): тестовый класс сам зовёт
 * ModuleConfigService::invalidateCache() в своём tearDown().
 */
trait InteractsWithPriceTiers
{
    protected function setPriceTiersModule(bool $enabled): void
    {
        $raw = AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY);
        $modules = $raw ? (json_decode($raw, true) ?: []) : [];
        $modules[ModuleConfigService::PRICE_TIERS_KEY] = [
            'label' => 'Цены от количества',
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
