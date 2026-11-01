<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AssemblyCostModuleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        ModuleConfigService::invalidateCache();
    }

    private function setModules(array $modules): void
    {
        AdminSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            ['value' => json_encode($modules), 'type' => 'json']
        );
        ModuleConfigService::invalidateCache();
    }

    public function test_enabled_when_flag_true(): void
    {
        $this->setModules(['assembly_cost' => ['enabled' => true]]);
        $this->assertTrue(ModuleConfigService::isAssemblyCostEnabled());
    }

    public function test_disabled_when_flag_false(): void
    {
        $this->setModules(['assembly_cost' => ['enabled' => false]]);
        $this->assertFalse(ModuleConfigService::isAssemblyCostEnabled());
    }

    public function test_disabled_when_key_absent(): void
    {
        $this->setModules(['heavy_orders' => ['enabled' => true]]);
        $this->assertFalse(ModuleConfigService::isAssemblyCostEnabled());
    }
}
