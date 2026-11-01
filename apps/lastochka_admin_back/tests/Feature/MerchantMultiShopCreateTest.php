<?php

namespace Tests\Feature;

use App\Jobs\MerchantMultiShop\CreateMerchantEntityCopiesJob;
use App\Models\MerchantBanner;
use App\Models\MerchantProductGroup;
use App\Models\MerchantSale;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\MerchantMultiShop\MultiShopCreateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * MID-237/238/229: создание акций/баннеров/товарных групп сразу в нескольких
 * магазинах (shop_ids) или во всех магазинах сети (all_shops).
 *
 * QUEUE_CONNECTION=sync в .env.testing — джоба копий выполняется инлайн,
 * поэтому shop_ids-сценарии проверяют конечное состояние БД целиком.
 */
class MerchantMultiShopCreateTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop1;
    protected Shop $shop2;
    protected Shop $shop3;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();

        $this->shop1 = $this->makeShop();
        $this->shop2 = $this->makeShop();
        $this->shop3 = $this->makeShop();

        $this->product = Product::create([
            'title' => 'MultiShop Product ' . uniqid(),
            'shop_id' => $this->shop1->id,
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
            'title' => 'MultiShop ' . uniqid(),
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

    private const SALE_MUTATION = '
        mutation createMerchantSale($input: CreateMerchantSaleInput!) {
            createMerchantSale(input: $input) {
                id
                title
                shop { id }
            }
        }
    ';

    private function saleVariables(array $overrides = []): array
    {
        return array_merge([
            'title' => 'MultiShop Sale ' . uniqid(),
            'discount_percent' => 15,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'product_ids' => [$this->product->id],
        ], $overrides);
    }

    /** @test */
    public function sale_is_created_in_all_selected_shops_in_network_mode()
    {
        $this->setNetworkCatalog(true);

        $vars = $this->saleVariables([
            'shop_ids' => [$this->shop1->id, $this->shop2->id, $this->shop3->id],
        ]);

        $response = $this->actingAs($this->user)->graphQL(self::SALE_MUTATION, ["input" => $vars]);

        // Мутация возвращает primary-акцию (первый магазин из списка)
        $response->assertJson([
            'data' => ['createMerchantSale' => ['shop' => ['id' => $this->shop1->id]]],
        ]);

        foreach ([$this->shop1, $this->shop2, $this->shop3] as $shop) {
            $this->assertDatabaseHas('merchant_sales', [
                'title' => $vars['title'],
                'shop_id' => $shop->id,
            ]);
        }

        // Товары привязаны и к копиям
        $copy = MerchantSale::where('title', $vars['title'])->where('shop_id', $this->shop3->id)->firstOrFail();
        $this->assertSame([$this->product->id], $copy->products()->pluck('products.id')->all());
    }

    /** @test */
    public function sale_all_shops_creates_primary_and_dispatches_copies_job()
    {
        $this->setNetworkCatalog(true);
        Queue::fake();

        $vars = $this->saleVariables(['all_shops' => true]);
        $anchorId = MultiShopCreateService::anchorShopId();

        $response = $this->actingAs($this->user)->graphQL(self::SALE_MUTATION, ["input" => $vars]);

        $response->assertJson([
            'data' => ['createMerchantSale' => ['shop' => ['id' => $anchorId]]],
        ]);

        $this->assertDatabaseHas('merchant_sales', [
            'title' => $vars['title'],
            'shop_id' => $anchorId,
        ]);

        Queue::assertPushed(CreateMerchantEntityCopiesJob::class, function ($job) use ($anchorId) {
            return $job->entity === CreateMerchantEntityCopiesJob::ENTITY_SALE
                && $job->allShops === true
                && $job->excludeShopId === $anchorId;
        });
    }

    /** @test */
    public function sale_legacy_single_shop_id_still_works()
    {
        $vars = $this->saleVariables(['shop_id' => $this->shop1->id]);

        $response = $this->actingAs($this->user)->graphQL(self::SALE_MUTATION, ["input" => $vars]);

        $response->assertJson([
            'data' => ['createMerchantSale' => ['shop' => ['id' => $this->shop1->id]]],
        ]);

        $this->assertSame(1, MerchantSale::where('title', $vars['title'])->count());
    }

    /** @test */
    public function sale_requires_some_shop_target()
    {
        $response = $this->actingAs($this->user)->graphQL(self::SALE_MUTATION, ["input" => $this->saleVariables()]);

        $this->assertNotEmpty($response->json('errors'), 'Мутация без shop_id/shop_ids/all_shops должна падать валидацией');
        $this->assertSame(0, MerchantSale::where('title', 'like', 'MultiShop Sale %')->whereDate('created_at', now())->count());
    }

    /** @test */
    public function sale_product_belongs_to_shop_still_enforced_outside_network_mode()
    {
        $this->setNetworkCatalog(false);

        // Товар из shop1, акция в shop2 — вне сети запрещено
        $vars = $this->saleVariables(['shop_id' => $this->shop2->id]);

        $response = $this->actingAs($this->user)->graphQL(self::SALE_MUTATION, ["input" => $vars]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, MerchantSale::where('title', $vars['title'])->count());
    }

    /** @test */
    public function banner_is_created_in_all_selected_shops()
    {
        $this->setNetworkCatalog(true);

        $title = 'MultiShop Banner ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation merchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                    shop { id }
                }
            }
        ', [
            'input' => [
                'title' => $title,
                'shop_ids' => [$this->shop1->id, $this->shop2->id],
            ],
        ]);

        $response->assertJson([
            'data' => ['merchantBannerCreate' => ['shop' => ['id' => $this->shop1->id]]],
        ]);

        foreach ([$this->shop1, $this->shop2] as $shop) {
            $this->assertDatabaseHas('merchant_banners', [
                'title' => $title,
                'shop_id' => $shop->id,
            ]);
        }
    }

    /** @test */
    public function product_group_is_created_in_all_selected_shops()
    {
        $this->setNetworkCatalog(true);

        $title = 'MultiShop Group ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation createMerchantProductGroup($input: CreateMerchantProductGroupInput!) {
                createMerchantProductGroup(input: $input) {
                    id
                    shop { id }
                }
            }
        ', [
            'input' => [
                'title' => $title,
                'shop_ids' => [$this->shop1->id, $this->shop2->id],
                'product_ids' => [$this->product->id],
            ],
        ]);

        $response->assertJson([
            'data' => ['createMerchantProductGroup' => ['shop' => ['id' => $this->shop1->id]]],
        ]);

        foreach ([$this->shop1, $this->shop2] as $shop) {
            $this->assertDatabaseHas('merchant_product_groups', [
                'title' => $title,
                'shop_id' => $shop->id,
            ]);
        }
    }

    /** @test */
    public function copies_job_is_idempotent_on_retry()
    {
        $args = [
            'title' => 'Idempotent Sale ' . uniqid(),
            'discount_percent' => 10,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'product_ids' => [$this->product->id],
        ];

        $job = new CreateMerchantEntityCopiesJob(
            CreateMerchantEntityCopiesJob::ENTITY_SALE,
            $args,
            [$this->shop2->id, $this->shop3->id],
            false,
            $this->shop1->id,
        );

        $job->handle();
        $job->handle(); // ретрай — копии не задваиваются

        $this->assertSame(1, MerchantSale::where('title', $args['title'])->where('shop_id', $this->shop2->id)->count());
        $this->assertSame(1, MerchantSale::where('title', $args['title'])->where('shop_id', $this->shop3->id)->count());
        $this->assertSame(0, MerchantSale::where('title', $args['title'])->where('shop_id', $this->shop1->id)->count());
    }

    /** @test */
    public function all_target_shop_ids_covers_every_alive_shop_except_excluded()
    {
        $ids = MultiShopCreateService::allTargetShopIds($this->shop1->id);

        $this->assertNotContains($this->shop1->id, $ids);
        $this->assertContains($this->shop2->id, $ids);
        $this->assertSame(Shop::query()->count() - 1, count($ids));
    }
}
