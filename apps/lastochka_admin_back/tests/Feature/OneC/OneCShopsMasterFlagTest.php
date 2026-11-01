<?php

declare(strict_types=1);

namespace Tests\Feature\OneC;

use App\Models\AdminSetting;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithOneCShopsMaster;
use Tests\TestCase;

class OneCShopsMasterFlagTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCShopsMaster;

    public function test_disabled_when_module_missing(): void
    {
        AdminSetting::setValue(ModuleConfigService::PROJECT_MODULES_KEY, json_encode([]), 'json');
        ModuleConfigService::invalidateCache();

        $this->assertFalse(ModuleConfigService::isOneCShopsMasterEnabled());
    }

    public function test_reads_enabled_flag(): void
    {
        $this->setOneCShopsMaster(true);
        $this->assertTrue(ModuleConfigService::isOneCShopsMasterEnabled());

        $this->setOneCShopsMaster(false);
        $this->assertFalse(ModuleConfigService::isOneCShopsMasterEnabled());
    }

    public function test_migration_added_module_disabled_by_default(): void
    {
        $modules = json_decode((string) AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY), true);

        $this->assertArrayHasKey('onec_shops_master', $modules);
        $this->assertFalse($modules['onec_shops_master']['enabled']);
        $this->assertSame('1С ведёт магазины и сотрудников', $modules['onec_shops_master']['label']);
    }
}
