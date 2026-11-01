<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Support\Facades\Cache;

/**
 * Модуль «Диапазоны стоимости доставки» (delivery_price_ranges, MID-280) в
 * тестах: переключение флага в блобе project_modules с сохранением соседних
 * ключей блоба (например marketplace_mode в MarketplaceDeliveryPriceRangesTest,
 * onec_shops_master в StoreDeliveryPriceRangesEventTest) — запись мержится,
 * а не затирается целиком.
 */
trait TogglesDeliveryPriceRangesModule
{
    protected function setDeliveryPriceRangesModule(bool $enabled): void
    {
        $projectModules = StorefrontSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY, []);

        if (! is_array($projectModules)) {
            $projectModules = [];
        }

        $projectModules['delivery_price_ranges'] = [
            'label' => 'Диапазоны стоимости доставки',
            'enabled' => $enabled,
        ];

        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode($projectModules, JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        // ModuleConfigService: общий кеш плюс memo singleton-экземпляра.
        Cache::flush();
        app(ModuleConfigService::class)->invalidateCache();
    }
}
