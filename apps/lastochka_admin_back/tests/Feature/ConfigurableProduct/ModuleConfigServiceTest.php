<?php

declare(strict_types=1);

namespace Tests\Feature\ConfigurableProduct;

use App\Models\AdminSetting;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ModuleConfigServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_returns_false_when_setting_absent(): void
    {
        AdminSetting::where('key', ModuleConfigService::PROJECT_MODULES_KEY)->delete();

        $this->assertFalse(ModuleConfigService::isConfigurableProductsEnabled());
    }

    public function test_returns_true_when_module_enabled(): void
    {
        $this->seedProjectModules([
            'configurable_products' => [
                'label' => 'Вариативные товары',
                'enabled' => true,
            ],
        ]);

        $this->assertTrue(ModuleConfigService::isConfigurableProductsEnabled());
    }

    public function test_returns_false_when_module_disabled(): void
    {
        $this->seedProjectModules([
            'configurable_products' => [
                'label' => 'Вариативные товары',
                'enabled' => false,
            ],
        ]);

        $this->assertFalse(ModuleConfigService::isConfigurableProductsEnabled());
    }

    public function test_returns_false_when_module_missing_from_blob(): void
    {
        $this->seedProjectModules([
            'other_module' => ['label' => 'X', 'enabled' => true],
        ]);

        $this->assertFalse(ModuleConfigService::isConfigurableProductsEnabled());
    }

    public function test_invalidate_cache_forces_db_re_read(): void
    {
        $this->seedProjectModules([
            'configurable_products' => ['label' => 'X', 'enabled' => false],
        ]);

        $this->assertFalse(ModuleConfigService::isConfigurableProductsEnabled());

        $this->seedProjectModules([
            'configurable_products' => ['label' => 'X', 'enabled' => true],
        ]);

        // Пока кеш — ответ прежний
        $this->assertFalse(ModuleConfigService::isConfigurableProductsEnabled());

        ModuleConfigService::invalidateCache();
        $this->assertTrue(ModuleConfigService::isConfigurableProductsEnabled());
    }

    private function seedProjectModules(array $modules): void
    {
        AdminSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json']
        );
    }
}
