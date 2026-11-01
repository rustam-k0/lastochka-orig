<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;

/**
 * Управление feature toggle модуля `b2b` (project_modules) в тестах.
 *
 * Тест-класс обязан вызывать invalidateB2bModuleCache() в tearDown,
 * чтобы кешированное значение не утекло в следующий тест.
 */
trait TogglesB2bModule
{
    protected function setB2bModule(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    ModuleConfigService::B2B_KEY => [
                        'label' => 'B2B',
                        'enabled' => $enabled,
                    ],
                ]),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
    }

    protected function invalidateB2bModuleCache(): void
    {
        app(ModuleConfigService::class)->invalidateCache();
    }
}
