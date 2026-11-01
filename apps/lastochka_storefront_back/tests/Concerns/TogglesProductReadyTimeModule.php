<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;

/**
 * Управление feature toggle модуля `product_ready_time` в тестах.
 * Тест-класс обязан вызывать invalidateProductReadyTimeCache() в tearDown.
 */
trait TogglesProductReadyTimeModule
{
    protected function setProductReadyTimeModule(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    ModuleConfigService::PRODUCT_READY_TIME_KEY => [
                        'label' => 'Время готовности товаров',
                        'enabled' => $enabled,
                    ],
                ]),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
    }

    protected function invalidateProductReadyTimeCache(): void
    {
        app(ModuleConfigService::class)->invalidateCache();
    }
}
