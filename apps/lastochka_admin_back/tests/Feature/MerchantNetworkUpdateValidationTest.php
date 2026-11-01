<?php

namespace Tests\Feature;

use App\Models\MerchantBanner;
use App\Models\MerchantProductGroup;
use App\Models\MerchantSale;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * MID-317: обновление товарной группы, баннера и акции в магазине сети
 * с товаром из сетевого каталога (карточка принадлежит якорю, не магазину
 * сущности) падало с «Validation failed». Вне сети проверка принадлежности
 * товара магазину остаётся.
 */
class MerchantNetworkUpdateValidationTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $anchorShop;
    protected Shop $shop;
    protected Product $networkProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();
        $this->anchorShop = $this->makeShop();
        $this->shop = $this->makeShop();

        // Товар принадлежит якорю, сущности живут в другом магазине сети
        $this->networkProduct = Product::create([
            'title' => 'Network Product ' . uniqid(),
            'shop_id' => $this->anchorShop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->setNetworkCatalog(false);
        Cache::forget(NetworkCatalogMode::CACHE_KEY);
        parent::tearDown();
    }

    private function makeShop(): Shop
    {
        return Shop::create([
            'title' => 'NetworkUpdate ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    private function setNetworkCatalog(bool $enabled): void
    {
        $modules = json_decode((string) DB::table('admin_settings')->where('key', 'project_modules')->value('value'), true) ?: [];
        $modules['network_catalog'] = ['label' => 'Сетевой каталог', 'enabled' => $enabled];
        DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
        );
        Cache::forget(NetworkCatalogMode::CACHE_KEY);
    }

    private const GROUP_MUTATION = '
        mutation updateMerchantProductGroup($id: Int!, $input: UpdateMerchantProductGroupInput!) {
            updateMerchantProductGroup(id: $id, input: $input) {
                id
                show_on_main
            }
        }
    ';

    private const BANNER_MUTATION = '
        mutation merchantBannerUpdate($id: Int!, $input: MerchantBannerUpdateInput!) {
            merchantBannerUpdate(id: $id, input: $input) {
                id
            }
        }
    ';

    private const SALE_MUTATION = '
        mutation updateMerchantSale($id: Int!, $input: UpdateMerchantSaleInput!) {
            updateMerchantSale(id: $id, input: $input) {
                id
            }
        }
    ';

    private function makeGroup(): MerchantProductGroup
    {
        return MerchantProductGroup::create([
            'shop_id' => $this->shop->id,
            'title' => 'Network Group ' . uniqid(),
            'show_on_main' => false,
            'is_active' => true,
        ]);
    }

    private function makeBanner(): MerchantBanner
    {
        return MerchantBanner::create([
            'shop_id' => $this->shop->id,
            'title' => 'Network Banner ' . uniqid(),
        ]);
    }

    private function makeSale(): MerchantSale
    {
        return MerchantSale::create([
            'shop_id' => $this->shop->id,
            'title' => 'Network Sale ' . uniqid(),
            'discount_percent' => 10,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
        ]);
    }

    /** @test */
    public function group_with_network_product_is_updated_in_network_mode()
    {
        $this->setNetworkCatalog(true);
        $group = $this->makeGroup();

        $response = $this->actingAs($this->user)->graphQL(self::GROUP_MUTATION, [
            'id' => $group->id,
            'input' => ['show_on_main' => true, 'product_ids' => [$this->networkProduct->id]],
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));
        $this->assertTrue($response->json('data.updateMerchantProductGroup.show_on_main'));
        $this->assertDatabaseHas('merchant_product_group_items', [
            'merchant_product_group_id' => $group->id,
            'product_id' => $this->networkProduct->id,
        ]);
    }

    /** @test */
    public function group_rejects_missing_product_in_network_mode()
    {
        $this->setNetworkCatalog(true);
        $group = $this->makeGroup();

        $response = $this->actingAs($this->user)->graphQL(self::GROUP_MUTATION, [
            'id' => $group->id,
            'input' => ['product_ids' => [999999999]],
        ]);

        $this->assertNotEmpty($response->json('errors.0.extensions.validation'));
    }

    /** @test */
    public function group_product_belongs_to_shop_still_enforced_outside_network_mode()
    {
        $this->setNetworkCatalog(false);
        $group = $this->makeGroup();

        $response = $this->actingAs($this->user)->graphQL(self::GROUP_MUTATION, [
            'id' => $group->id,
            'input' => ['product_ids' => [$this->networkProduct->id]],
        ]);

        $this->assertArrayHasKey('input.product_ids', $response->json('errors.0.extensions.validation') ?? []);
    }

    /** @test */
    public function banner_with_network_product_is_updated_in_network_mode()
    {
        $this->setNetworkCatalog(true);
        $banner = $this->makeBanner();

        $response = $this->actingAs($this->user)->graphQL(self::BANNER_MUTATION, [
            'id' => $banner->id,
            'input' => ['link_type' => 'PRODUCT', 'product_id' => $this->networkProduct->id],
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseHas('merchant_banners', [
            'id' => $banner->id,
            'link_type' => MerchantBanner::LINK_TYPE_PRODUCT,
            'product_id' => $this->networkProduct->id,
        ]);
    }

    /** @test */
    public function banner_product_belongs_to_shop_still_enforced_outside_network_mode()
    {
        $this->setNetworkCatalog(false);
        $banner = $this->makeBanner();

        $response = $this->actingAs($this->user)->graphQL(self::BANNER_MUTATION, [
            'id' => $banner->id,
            'input' => ['link_type' => 'PRODUCT', 'product_id' => $this->networkProduct->id],
        ]);

        $this->assertArrayHasKey('input.product_id', $response->json('errors.0.extensions.validation') ?? []);
    }

    /** @test */
    public function sale_with_network_product_is_updated_in_network_mode()
    {
        $this->setNetworkCatalog(true);
        $sale = $this->makeSale();

        $response = $this->actingAs($this->user)->graphQL(self::SALE_MUTATION, [
            'id' => $sale->id,
            'input' => ['product_ids' => [$this->networkProduct->id]],
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));
    }

    /** @test */
    public function sale_product_belongs_to_shop_still_enforced_outside_network_mode()
    {
        $this->setNetworkCatalog(false);
        $sale = $this->makeSale();

        $response = $this->actingAs($this->user)->graphQL(self::SALE_MUTATION, [
            'id' => $sale->id,
            'input' => ['product_ids' => [$this->networkProduct->id]],
        ]);

        $this->assertArrayHasKey('input.product_ids', $response->json('errors.0.extensions.validation') ?? []);
    }
}
