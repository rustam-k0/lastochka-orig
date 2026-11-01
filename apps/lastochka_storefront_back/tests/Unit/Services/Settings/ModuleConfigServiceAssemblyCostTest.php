<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Settings;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ModuleConfigServiceAssemblyCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  array<string, mixed>  $modules
     */
    private function setModules(array $modules): ModuleConfigService
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode($modules, JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        $svc = app(ModuleConfigService::class);
        $svc->invalidateCache();

        return $svc;
    }

    public function test_enabled(): void
    {
        $svc = $this->setModules(['assembly_cost' => ['enabled' => true]]);

        $this->assertTrue($svc->isAssemblyCostEnabled());
    }

    public function test_disabled(): void
    {
        $svc = $this->setModules(['assembly_cost' => ['enabled' => false]]);

        $this->assertFalse($svc->isAssemblyCostEnabled());
    }

    public function test_absent_key_false(): void
    {
        $svc = $this->setModules(['heavy_orders' => ['enabled' => true]]);

        $this->assertFalse($svc->isAssemblyCostEnabled());
    }
}
