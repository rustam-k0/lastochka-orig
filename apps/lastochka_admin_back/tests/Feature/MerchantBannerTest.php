<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MerchantBanner;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Transformers\MerchantBannerEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantBannerTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;
    protected Product $product;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();

        $this->shop = Shop::create([
            'title' => 'Test Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $this->product = Product::create([
            'title' => 'Test Product ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);

        $this->category = Category::firstOrCreate(
            ['title' => 'Test Category'],
            ['title' => 'Test Category', 'sort_order' => 0]
        );
    }

    protected function createShop(?int $sphereId = null): Shop
    {
        return Shop::create([
            'title' => 'Test Shop ' . uniqid(),
            'sphere_id' => $sphereId ?? $this->shop->sphere_id,
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    protected function createProduct(int $shopId): Product
    {
        return Product::create([
            'title' => 'Test Product ' . uniqid(),
            'shop_id' => $shopId,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
    }

    protected function createBanner(array $attributes = []): MerchantBanner
    {
        return MerchantBanner::create(array_merge([
            'title' => 'Test Banner ' . uniqid(),
            'link_type' => MerchantBanner::LINK_TYPE_NONE,
            'shop_id' => $this->shop->id,
            'is_active' => true,
            'position' => 0,
        ], $attributes));
    }

    /** @test */
    public function it_can_create_merchant_banner()
    {
        $mutation = '
            mutation MerchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                    title
                    link_type
                    shop_id
                    is_active
                    position
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Тестовый баннер',
                    'shop_id' => $this->shop->id,
                    'is_active' => true,
                    'position' => 1,
                ],
            ]);

        $response->assertJson([
            'data' => [
                'merchantBannerCreate' => [
                    'title' => 'Тестовый баннер',
                    'link_type' => 'NONE',
                    'shop_id' => $this->shop->id,
                    'is_active' => true,
                    'position' => 1,
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_banners', [
            'title' => 'Тестовый баннер',
            'shop_id' => $this->shop->id,
        ]);
    }

    /** @test */
    public function it_can_create_banner_with_product_link()
    {
        $mutation = '
            mutation MerchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                    title
                    link_type
                    product_id
                    product {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Баннер с товаром',
                    'shop_id' => $this->shop->id,
                    'link_type' => 'PRODUCT',
                    'product_id' => $this->product->id,
                ],
            ]);

        $response->assertJson([
            'data' => [
                'merchantBannerCreate' => [
                    'title' => 'Баннер с товаром',
                    'link_type' => 'PRODUCT',
                    'product_id' => $this->product->id,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_can_create_banner_with_category_link()
    {
        $mutation = '
            mutation MerchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                    title
                    link_type
                    category_id
                    category {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Баннер с категорией',
                    'shop_id' => $this->shop->id,
                    'link_type' => 'CATEGORY',
                    'category_id' => $this->category->id,
                ],
            ]);

        $response->assertJson([
            'data' => [
                'merchantBannerCreate' => [
                    'title' => 'Баннер с категорией',
                    'link_type' => 'CATEGORY',
                    'category_id' => $this->category->id,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_validates_product_belongs_to_shop()
    {
        $anotherShop = $this->createShop();
        $productFromAnotherShop = $this->createProduct($anotherShop->id);

        $mutation = '
            mutation MerchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Баннер',
                    'shop_id' => $this->shop->id,
                    'link_type' => 'PRODUCT',
                    'product_id' => $productFromAnotherShop->id,
                ],
            ]);

        $response->assertGraphQLValidationError('input.product_id', 'Товар не принадлежит указанному магазину.');
    }

    /** @test */
    public function it_requires_product_id_when_link_type_is_product()
    {
        $mutation = '
            mutation MerchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Баннер',
                    'shop_id' => $this->shop->id,
                    'link_type' => 'PRODUCT',
                ],
            ]);

        $response->assertGraphQLValidationError('input.product_id', 'ID товара обязателен при link_type = product');
    }

    /** @test */
    public function it_requires_category_id_when_link_type_is_category()
    {
        $mutation = '
            mutation MerchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Баннер',
                    'shop_id' => $this->shop->id,
                    'link_type' => 'CATEGORY',
                ],
            ]);

        $response->assertGraphQLValidationError('input.category_id', 'ID категории обязателен при link_type = category');
    }

    /** @test */
    public function it_can_update_merchant_banner()
    {
        $banner = $this->createBanner(['title' => 'Старое название']);

        $mutation = '
            mutation MerchantBannerUpdate($id: Int!, $input: MerchantBannerUpdateInput!) {
                merchantBannerUpdate(id: $id, input: $input) {
                    id
                    title
                    is_active
                    position
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $banner->id,
                'input' => [
                    'title' => 'Новое название',
                    'is_active' => false,
                    'position' => 5,
                ],
            ]);

        $response->assertJson([
            'data' => [
                'merchantBannerUpdate' => [
                    'title' => 'Новое название',
                    'is_active' => false,
                    'position' => 5,
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_banners', [
            'id' => $banner->id,
            'title' => 'Новое название',
            'is_active' => false,
            'position' => 5,
        ]);
    }

    /** @test */
    public function it_can_change_link_type_on_update()
    {
        $banner = $this->createBanner([
            'link_type' => MerchantBanner::LINK_TYPE_NONE,
        ]);

        $mutation = '
            mutation MerchantBannerUpdate($id: Int!, $input: MerchantBannerUpdateInput!) {
                merchantBannerUpdate(id: $id, input: $input) {
                    id
                    link_type
                    product_id
                    category_id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $banner->id,
                'input' => [
                    'link_type' => 'PRODUCT',
                    'product_id' => $this->product->id,
                ],
            ]);

        $response->assertJson([
            'data' => [
                'merchantBannerUpdate' => [
                    'link_type' => 'PRODUCT',
                    'product_id' => $this->product->id,
                    'category_id' => null,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_clears_product_when_changing_to_category_link()
    {
        $banner = $this->createBanner([
            'link_type' => MerchantBanner::LINK_TYPE_PRODUCT,
            'product_id' => $this->product->id,
        ]);

        $mutation = '
            mutation MerchantBannerUpdate($id: Int!, $input: MerchantBannerUpdateInput!) {
                merchantBannerUpdate(id: $id, input: $input) {
                    id
                    link_type
                    product_id
                    category_id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $banner->id,
                'input' => [
                    'link_type' => 'CATEGORY',
                    'category_id' => $this->category->id,
                ],
            ]);

        $response->assertJson([
            'data' => [
                'merchantBannerUpdate' => [
                    'link_type' => 'CATEGORY',
                    'product_id' => null,
                    'category_id' => $this->category->id,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_can_delete_merchant_banner()
    {
        $banner = $this->createBanner();

        $mutation = '
            mutation MerchantBannerDelete($id: Int!) {
                merchantBannerDelete(id: $id) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $banner->id,
            ]);

        $response->assertJson([
            'data' => [
                'merchantBannerDelete' => [
                    'id' => $banner->id,
                ],
            ],
        ]);

        $this->assertSoftDeleted('merchant_banners', [
            'id' => $banner->id,
        ]);
    }

    /** @test */
    public function it_can_query_merchant_banners()
    {
        $this->createBanner(['is_active' => true]);
        $this->createBanner(['is_active' => true]);
        $this->createBanner(['is_active' => true]);
        $this->createBanner(['is_active' => false]);

        $query = '
            query MerchantBanners($shop_id: Int, $is_active: Boolean) {
                merchantBanners(shop_id: $shop_id, is_active: $is_active) {
                    data {
                        id
                        title
                        is_active
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'is_active' => true,
            ]);

        $response->assertJsonPath('data.merchantBanners.paginatorInfo.total', 3);
    }

    /** @test */
    public function it_can_filter_by_link_type()
    {
        $this->createBanner(['link_type' => MerchantBanner::LINK_TYPE_NONE]);
        $this->createBanner([
            'link_type' => MerchantBanner::LINK_TYPE_PRODUCT,
            'product_id' => $this->product->id,
        ]);
        $this->createBanner([
            'link_type' => MerchantBanner::LINK_TYPE_CATEGORY,
            'category_id' => $this->category->id,
        ]);

        $query = '
            query MerchantBanners($shop_id: Int, $link_type: MerchantBannerLinkType) {
                merchantBanners(shop_id: $shop_id, link_type: $link_type) {
                    data {
                        id
                        link_type
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'link_type' => 'PRODUCT',
            ]);

        $response->assertJsonPath('data.merchantBanners.paginatorInfo.total', 1);
    }

    /** @test */
    public function it_can_search_banners_by_title()
    {
        $this->createBanner(['title' => 'Акция на молоко']);
        $this->createBanner(['title' => 'Скидки на хлеб']);
        $this->createBanner(['title' => 'Новинки']);

        $query = '
            query MerchantBanners($shop_id: Int, $search: String) {
                merchantBanners(shop_id: $shop_id, search: $search) {
                    data {
                        id
                        title
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'search' => 'молоко',
            ]);

        $response->assertJsonPath('data.merchantBanners.paginatorInfo.total', 1);
    }

    /** @test */
    public function it_sorts_by_position_by_default()
    {
        $this->createBanner(['title' => 'Third', 'position' => 3]);
        $this->createBanner(['title' => 'First', 'position' => 1]);
        $this->createBanner(['title' => 'Second', 'position' => 2]);

        $query = '
            query MerchantBanners($shop_id: Int) {
                merchantBanners(shop_id: $shop_id) {
                    data {
                        title
                        position
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
            ]);

        $data = $response->json('data.merchantBanners.data');
        $this->assertEquals('First', $data[0]['title']);
        $this->assertEquals('Second', $data[1]['title']);
        $this->assertEquals('Third', $data[2]['title']);
    }

    /** @test */
    public function it_can_create_banner_with_display_category()
    {
        $mutation = '
            mutation MerchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                    display_category_id
                    display_category {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Баннер страницы категории',
                    'shop_id' => $this->shop->id,
                    'display_category_id' => $this->category->id,
                ],
            ]);

        $response->assertJson([
            'data' => [
                'merchantBannerCreate' => [
                    'display_category_id' => $this->category->id,
                    'display_category' => [
                        'id' => $this->category->id,
                    ],
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_banners', [
            'title' => 'Баннер страницы категории',
            'display_category_id' => $this->category->id,
        ]);
    }

    /** @test */
    public function it_creates_main_page_banner_without_display_category_by_default()
    {
        $mutation = '
            mutation MerchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                    display_category_id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Баннер главной',
                    'shop_id' => $this->shop->id,
                ],
            ]);

        $response->assertJsonMissingPath('errors');
        $response->assertJsonPath('data.merchantBannerCreate.display_category_id', null);
    }

    /** @test */
    public function it_can_update_display_category()
    {
        $banner = $this->createBanner();

        $mutation = '
            mutation MerchantBannerUpdate($id: Int!, $input: MerchantBannerUpdateInput!) {
                merchantBannerUpdate(id: $id, input: $input) {
                    id
                    display_category_id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $banner->id,
                'input' => [
                    'display_category_id' => $this->category->id,
                ],
            ]);

        $response->assertJsonPath('data.merchantBannerUpdate.display_category_id', $this->category->id);

        $this->assertDatabaseHas('merchant_banners', [
            'id' => $banner->id,
            'display_category_id' => $this->category->id,
        ]);
    }

    /** @test */
    public function it_can_clear_display_category_on_update()
    {
        $banner = $this->createBanner(['display_category_id' => $this->category->id]);

        $mutation = '
            mutation MerchantBannerUpdate($id: Int!, $input: MerchantBannerUpdateInput!) {
                merchantBannerUpdate(id: $id, input: $input) {
                    id
                    display_category_id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $banner->id,
                'input' => [
                    'display_category_id' => null,
                ],
            ]);

        $response->assertJsonPath('data.merchantBannerUpdate.display_category_id', null);

        $this->assertDatabaseHas('merchant_banners', [
            'id' => $banner->id,
            'display_category_id' => null,
        ]);
    }

    /** @test */
    public function it_validates_display_category_exists()
    {
        $mutation = '
            mutation MerchantBannerCreate($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Баннер',
                    'shop_id' => $this->shop->id,
                    'display_category_id' => 999999999,
                ],
            ]);

        $response->assertGraphQLValidationError('input.display_category_id', 'Указанная категория для показа не найдена');
    }

    /** @test */
    public function transformer_includes_display_category_in_event()
    {
        $banner = $this->createBanner(['display_category_id' => $this->category->id]);

        $event = app(MerchantBannerEventTransformer::class)->toEvent($banner);

        $this->assertSame((string) $this->category->id, $event['display_category_id']);
        $this->assertSame(config('rabbitmq-events.source'), $event['display_category_source']);
    }

    /** @test */
    public function transformer_sends_null_display_category_for_main_banner()
    {
        $banner = $this->createBanner();

        $event = app(MerchantBannerEventTransformer::class)->toEvent($banner);

        $this->assertNull($event['display_category_id']);
        $this->assertNull($event['display_category_source']);
    }

    /** @test */
    public function it_can_get_single_banner()
    {
        $banner = $this->createBanner(['title' => 'Тестовый баннер']);

        $query = '
            query MerchantBanner($id: Int) {
                merchantBanner(id: $id) {
                    id
                    title
                    shop {
                        id
                        title
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($query, [
                'id' => $banner->id,
            ]);

        $response->assertJson([
            'data' => [
                'merchantBanner' => [
                    'id' => $banner->id,
                    'title' => 'Тестовый баннер',
                    'shop' => [
                        'id' => $this->shop->id,
                    ],
                ],
            ],
        ]);
    }
}
