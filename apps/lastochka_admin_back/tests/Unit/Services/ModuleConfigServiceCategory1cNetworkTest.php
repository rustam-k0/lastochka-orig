<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class ModuleConfigServiceCategory1cNetworkTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    /**
     * Без anchor_shop_id якорь резолвится по shops.default = 1 (MID-239,
     * покрыто в Category1cNetworkAnchorFallbackTest). Null ожидаем только
     * когда default-магазина нет — снимаем его со стенда (откатится транзакцией).
     */
    private function clearDefaultShops(): void
    {
        DB::table('shops')->where('default', 1)->update(['default' => 0]);
        ModuleConfigService::invalidateCache();
    }

    public function test_disabled_module_returns_false_and_null_anchor(): void
    {
        $this->disableCategory1cNetworkMode();

        $this->assertFalse(ModuleConfigService::isCategory1cNetworkMappingEnabled());
        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_missing_module_returns_false_and_null_anchor(): void
    {
        $this->writeCategory1cNetworkModule(null);

        $this->assertFalse(ModuleConfigService::isCategory1cNetworkMappingEnabled());
        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_enabled_with_anchor_returns_true_and_anchor(): void
    {
        $this->enableCategory1cNetworkMode(5);

        $this->assertTrue(ModuleConfigService::isCategory1cNetworkMappingEnabled());
        $this->assertSame(5, ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_enabled_without_anchor_returns_null_anchor(): void
    {
        $this->clearDefaultShops();

        $this->writeCategory1cNetworkModule([
            'label' => 'x',
            'enabled' => true,
        ]);

        $this->assertTrue(ModuleConfigService::isCategory1cNetworkMappingEnabled());
        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_enabled_with_invalid_anchor_returns_null_anchor(): void
    {
        $this->clearDefaultShops();

        $this->writeCategory1cNetworkModule([
            'label' => 'x',
            'enabled' => true,
            'anchor_shop_id' => 'not-a-number',
        ]);

        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());

        $this->writeCategory1cNetworkModule([
            'label' => 'x',
            'enabled' => true,
            'anchor_shop_id' => 0,
        ]);

        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }
}
