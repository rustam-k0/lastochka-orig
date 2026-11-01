<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Queries\AdminSetting\AdminSettingQueries;
use App\Models\AdminSetting;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithPriceTiers;
use Tests\TestCase;

/**
 * Фича-флаг «Цены от количества» (price_tiers, MID-308): дефолт выключен,
 * чтение через ModuleConfigService, запись миграцией-сидером.
 */
class PriceTiersFlagTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithPriceTiers;

    protected function tearDown(): void
    {
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    public function test_disabled_when_module_missing(): void
    {
        AdminSetting::setValue(ModuleConfigService::PROJECT_MODULES_KEY, json_encode([]), 'json');
        ModuleConfigService::invalidateCache();

        $this->assertFalse(ModuleConfigService::isPriceTiersEnabled());
    }

    public function test_reads_enabled_flag(): void
    {
        $this->setPriceTiersModule(true);
        $this->assertTrue(ModuleConfigService::isPriceTiersEnabled());

        $this->setPriceTiersModule(false);
        $this->assertFalse(ModuleConfigService::isPriceTiersEnabled());
    }

    public function test_migration_added_module_disabled_by_default(): void
    {
        $modules = json_decode((string) AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY), true);

        $this->assertArrayHasKey('price_tiers', $modules);
        $this->assertFalse($modules['price_tiers']['enabled']);
        $this->assertSame('Цены от количества', $modules['price_tiers']['label']);
    }

    public function test_module_present_in_defaults_and_disabled(): void
    {
        AdminSetting::where('key', ModuleConfigService::PROJECT_MODULES_KEY)->delete();

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertArrayHasKey('price_tiers', $decoded);
        $this->assertFalse($decoded['price_tiers']['enabled']);
        $this->assertSame('Цены от количества', $decoded['price_tiers']['label']);
    }

    public function test_module_merged_into_stored_blob(): void
    {
        AdminSetting::setValue(ModuleConfigService::PROJECT_MODULES_KEY, json_encode([
            'bonuses' => ['label' => 'Бонусная система', 'enabled' => true],
        ]), 'json');

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertTrue($decoded['bonuses']['enabled']);
        $this->assertArrayHasKey('price_tiers', $decoded);
        $this->assertFalse($decoded['price_tiers']['enabled']);
    }
}
