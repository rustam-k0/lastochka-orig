<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NetworkCatalogModuleConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_disabled_by_default(): void
    {
        $this->assertFalse(app(ModuleConfigService::class)->isNetworkCatalogEnabled());
    }

    public function test_enabled_when_module_on(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => true],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        $svc = app(ModuleConfigService::class);
        $svc->invalidateCache();

        $this->assertTrue($svc->isNetworkCatalogEnabled());
    }

    public function test_disabled_when_module_off(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => false],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        $svc = app(ModuleConfigService::class);
        $svc->invalidateCache();

        $this->assertFalse($svc->isNetworkCatalogEnabled());
    }
}
