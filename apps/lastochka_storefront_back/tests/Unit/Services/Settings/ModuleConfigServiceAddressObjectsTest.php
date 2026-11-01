<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Settings;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ModuleConfigServiceAddressObjectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_returns_false_when_setting_absent(): void
    {
        $service = app(ModuleConfigService::class);

        $this->assertFalse($service->isAddressObjectsEnabled());
    }

    public function test_returns_false_when_module_disabled(): void
    {
        $this->seedProjectModules([
            ModuleConfigService::ADDRESS_OBJECTS_KEY => ['type' => 'boolean', 'enabled' => false],
        ]);

        $this->assertFalse(app(ModuleConfigService::class)->isAddressObjectsEnabled());
    }

    public function test_returns_true_when_module_enabled(): void
    {
        $this->seedProjectModules([
            ModuleConfigService::ADDRESS_OBJECTS_KEY => ['type' => 'boolean', 'enabled' => true],
        ]);

        $this->assertTrue(app(ModuleConfigService::class)->isAddressObjectsEnabled());
    }

    public function test_invalidate_cache_clears_address_objects_cache(): void
    {
        $this->seedProjectModules([
            ModuleConfigService::ADDRESS_OBJECTS_KEY => ['type' => 'boolean', 'enabled' => true],
        ]);

        $service = app(ModuleConfigService::class);
        $this->assertTrue($service->isAddressObjectsEnabled());

        StorefrontSetting::where('key', ModuleConfigService::PROJECT_MODULES_KEY)
            ->update([
                'value' => json_encode([
                    ModuleConfigService::ADDRESS_OBJECTS_KEY => ['type' => 'boolean', 'enabled' => false],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        $service->invalidateCache();

        $this->assertFalse($service->isAddressObjectsEnabled());
    }

    /**
     * @param  array<string, mixed>  $modules
     */
    private function seedProjectModules(array $modules): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode($modules, JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
    }
}
