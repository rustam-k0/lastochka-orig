<?php

namespace Tests\Feature;

use App\GraphQL\Queries\AdminSetting\AdminSettingQueries;
use App\Models\AdminSetting;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HeavyOrdersModuleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(ModuleConfigService::CACHE_KEY_HEAVY_ORDERS);
    }

    protected function tearDown(): void
    {
        Cache::forget(ModuleConfigService::CACHE_KEY_HEAVY_ORDERS);
        parent::tearDown();
    }

    /** @test */
    public function heavy_orders_module_present_in_defaults()
    {
        AdminSetting::where('key', 'project_modules')->delete();

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertArrayHasKey('heavy_orders', $decoded);
        $this->assertFalse($decoded['heavy_orders']['enabled']);
        $this->assertSame('Тяжёлые заказы', $decoded['heavy_orders']['label']);
    }

    /** @test */
    public function project_modules_merges_new_default_keys_into_stored_blob()
    {
        // Сохранённый блоб БЕЗ heavy_orders (как на dev до фичи)
        AdminSetting::setValue('project_modules', json_encode([
            'bonuses' => ['label' => 'Бонусная система', 'enabled' => true],
        ]), 'json');

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        // Сохранённое значение приоритетно, новые дефолтные ключи домержены
        $this->assertTrue($decoded['bonuses']['enabled']);
        $this->assertArrayHasKey('heavy_orders', $decoded);
        $this->assertFalse($decoded['heavy_orders']['enabled']);
    }

    /** @test */
    public function is_heavy_orders_enabled_reads_blob()
    {
        AdminSetting::setValue('project_modules', json_encode([
            'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => true],
        ]), 'json');

        $this->assertTrue(ModuleConfigService::isHeavyOrdersEnabled());
    }

    /** @test */
    public function is_heavy_orders_enabled_defaults_to_false_when_key_absent()
    {
        AdminSetting::setValue('project_modules', json_encode([
            'bonuses' => ['label' => 'Бонусная система', 'enabled' => true],
        ]), 'json');

        $this->assertFalse(ModuleConfigService::isHeavyOrdersEnabled());
    }
}
