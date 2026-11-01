<?php

declare(strict_types=1);

namespace Tests\Unit\Category1c;

use App\GraphQL\Queries\AdminSetting\AdminSettingQueries;
use App\Models\Shop;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

/**
 * MID-239: anchor_shop_id терялся из блоба project_modules при пересохранении
 * настроек, и сетевой маппинг категорий 1С молча выключался целиком (бэкенд
 * fail-safe возвращал null, фронт показывал «Выберите магазин»).
 *
 * Фикс: при включённом модуле и отсутствующем/некорректном anchor_shop_id
 * якорь резолвится по сети — shops.default = 1 (та же семантика, что у
 * network_catalog). Явно заданный anchor_shop_id остаётся приоритетным.
 * projectModules() отдаёт фронту уже резолвленный anchor_shop_id.
 */
class Category1cNetworkAnchorFallbackTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(bool $default = false): Shop
    {
        $shop = Shop::query()->create([
            'title' => 'fixture-anchor-' . uniqid(),
            'code' => 'anchor-fb-' . uniqid(),
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);

        if ($default) {
            // 'default' не входит в $fillable Shop — только прямой UPDATE
            DB::table('shops')->where('id', $shop->id)->update(['default' => 1]);
        }

        return $shop;
    }

    /** Снимает default со всех магазинов стенда (откатится транзакцией). */
    private function clearDefaultShops(): void
    {
        DB::table('shops')->where('default', 1)->update(['default' => 0]);
        ModuleConfigService::invalidateCache();
    }

    public function test_anchor_falls_back_to_default_shop_when_field_missing(): void
    {
        $this->clearDefaultShops();
        $anchor = $this->makeShop(default: true);

        $this->writeCategory1cNetworkModule([
            'label' => 'Сетевой маппинг категорий 1С',
            'enabled' => true,
            // anchor_shop_id отсутствует — так выглядит блоб после
            // пересохранения настроек через UI
        ]);

        $this->assertSame($anchor->id, ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_explicit_anchor_takes_precedence_over_default_shop(): void
    {
        $this->clearDefaultShops();
        $this->makeShop(default: true);
        $explicit = $this->makeShop();

        $this->enableCategory1cNetworkMode($explicit->id);

        $this->assertSame($explicit->id, ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_anchor_null_when_module_disabled(): void
    {
        $this->clearDefaultShops();
        $this->makeShop(default: true);

        $this->disableCategory1cNetworkMode();

        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_project_modules_query_exposes_resolved_anchor(): void
    {
        $this->clearDefaultShops();
        $anchor = $this->makeShop(default: true);

        $this->writeCategory1cNetworkModule([
            'label' => 'Сетевой маппинг категорий 1С',
            'enabled' => true,
        ]);

        $config = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertSame(
            $anchor->id,
            $config[ModuleConfigService::CATEGORY_1C_NETWORK_KEY]['anchor_shop_id'] ?? null
        );
    }
}
