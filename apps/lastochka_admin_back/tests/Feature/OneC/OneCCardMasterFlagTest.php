<?php

declare(strict_types=1);

namespace Tests\Feature\OneC;

use App\Models\AdminSetting;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithOneCCardMaster;
use Tests\TestCase;

class OneCCardMasterFlagTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCCardMaster;

    public function test_disabled_when_module_missing(): void
    {
        AdminSetting::setValue(ModuleConfigService::PROJECT_MODULES_KEY, json_encode([]), 'json');
        ModuleConfigService::invalidateCache();

        $this->assertFalse(ModuleConfigService::isOneCCardMasterEnabled());
    }

    public function test_reads_enabled_flag(): void
    {
        $this->setOneCCardMaster(true);
        $this->assertTrue(ModuleConfigService::isOneCCardMasterEnabled());

        $this->setOneCCardMaster(false);
        $this->assertFalse(ModuleConfigService::isOneCCardMasterEnabled());
    }

    public function test_migration_added_module_disabled_by_default(): void
    {
        $modules = json_decode((string) AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY), true);

        $this->assertArrayHasKey('onec_card_master', $modules);
        $this->assertFalse($modules['onec_card_master']['enabled']);
    }
}
