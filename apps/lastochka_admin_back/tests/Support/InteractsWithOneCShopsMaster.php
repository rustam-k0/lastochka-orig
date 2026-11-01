<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AdminSetting;
use App\Services\ModuleConfigService;

/**
 * Включение/выключение модуля onec_shops_master («1С ведёт магазины и сотрудников», MID-306).
 * tearDown сбрасывает кеш ModuleConfigService: array-кеш живёт весь процесс
 * PHPUnit, а DatabaseTransactions откатывает только БД.
 */
trait InteractsWithOneCShopsMaster
{
    protected function tearDown(): void
    {
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    protected function setOneCShopsMaster(bool $enabled): void
    {
        $raw = AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY);
        $modules = $raw ? (json_decode($raw, true) ?: []) : [];
        $modules[ModuleConfigService::ONEC_SHOPS_MASTER_KEY] = [
            'label' => '1С ведёт магазины и сотрудников',
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
