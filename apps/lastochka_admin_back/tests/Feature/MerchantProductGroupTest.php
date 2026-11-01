<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\MerchantProductGroup;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantProductGroupTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;
    protected Product $product1;
    protected Product $product2;

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

        $this->product1 = Product::create([
            'title' => 'Test Product 1 ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);

        $this->product2 = Product::create([
            'title' => 'Test Product 2 ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
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

    protected function createGroup(array $attributes = []): MerchantProductGroup
    {
        return MerchantProductGroup::create(array_merge([
            'shop_id' => $this->shop->id,
            'title' => 'Test Group ' . uniqid(),
            'show_on_main' => false,
            'show_on_merchant_page' => true,
            'show_in_merchant_catalog' => false,
            'sort_order' => 0,
            'is_active' => true,
        ], $attributes));
    }

    /** @test */
    public function it_can_create_merchant_product_group()
    {
        $mutation = '
            mutation CreateMerchantProductGroup($input: CreateMerchantProductGroupInput!) {
                createMerchantProductGroup(input: $input) {
                    id
                    title
                    shop_id
                    show_on_main
                    show_on_merchant_page
                    show_in_merchant_catalog
                    is_active
                    products_count
                    products {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'title' => 'Тестовая группа',
                    'show_on_main' => true,
                    'show_on_merchant_page' => true,
                    'product_ids' => [$this->product1->id, $this->product2->id],
                ],
            ]);

        $response->assertJson([
            'data' => [
                'createMerchantProductGroup' => [
                    'title' => 'Тестовая группа',
                    'shop_id' => $this->shop->id,
                    'show_on_main' => true,
                    'show_on_merchant_page' => true,
                    'is_active' => true,
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_product_groups', [
            'title' => 'Тестовая группа',
            'shop_id' => $this->shop->id,
        ]);

        $this->assertDatabaseHas('merchant_product_group_items', [
            'product_id' => $this->product1->id,
        ]);

        $this->assertDatabaseHas('merchant_product_group_items', [
            'product_id' => $this->product2->id,
        ]);
    }

    /** @test */
    public function it_validates_product_belongs_to_shop()
    {
        $anotherShop = $this->createShop();
        $productFromAnotherShop = $this->createProduct($anotherShop->id);

        $mutation = '
            mutation CreateMerchantProductGroup($input: CreateMerchantProductGroupInput!) {
                createMerchantProductGroup(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'title' => 'Тестовая группа',
                    'product_ids' => [$productFromAnotherShop->id],
                ],
            ]);

        $response->assertGraphQLValidationKeys(['input.product_ids']);
    }

    /** @test */
    public function it_can_update_merchant_product_group()
    {
        $group = $this->createGroup(['title' => 'Старое название']);

        $group->products()->attach([$this->product1->id]);

        $mutation = '
            mutation UpdateMerchantProductGroup($id: Int!, $input: UpdateMerchantProductGroupInput!) {
                updateMerchantProductGroup(id: $id, input: $input) {
                    id
                    title
                    show_on_main
                    products {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $group->id,
                'input' => [
                    'title' => 'Новое название',
                    'show_on_main' => true,
                    'product_ids' => [$this->product1->id, $this->product2->id],
                ],
            ]);

        $response->assertJson([
            'data' => [
                'updateMerchantProductGroup' => [
                    'title' => 'Новое название',
                    'show_on_main' => true,
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_product_groups', [
            'id' => $group->id,
            'title' => 'Новое название',
            'show_on_main' => true,
        ]);
    }

    /** @test */
    public function it_can_delete_merchant_product_group()
    {
        $group = $this->createGroup();

        $mutation = '
            mutation DeleteMerchantProductGroup($id: Int!) {
                deleteMerchantProductGroup(id: $id) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $group->id,
            ]);

        $response->assertJson([
            'data' => [
                'deleteMerchantProductGroup' => [
                    'id' => $group->id,
                ],
            ],
        ]);

        $this->assertSoftDeleted('merchant_product_groups', [
            'id' => $group->id,
        ]);
    }

    /** @test */
    public function it_can_query_merchant_product_groups()
    {
        $this->createGroup(['is_active' => true]);
        $this->createGroup(['is_active' => true]);
        $this->createGroup(['is_active' => true]);
        $this->createGroup(['is_active' => false]);

        $query = '
            query MerchantProductGroups($shop_id: Int, $is_active: Boolean) {
                merchantProductGroups(shop_id: $shop_id, is_active: $is_active) {
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

        $response->assertJsonPath('data.merchantProductGroups.paginatorInfo.total', 3);
    }

    /** @test */
    public function it_can_filter_by_show_on_main()
    {
        $this->createGroup(['show_on_main' => true]);
        $this->createGroup(['show_on_main' => false]);
        $this->createGroup(['show_on_main' => false]);

        $query = '
            query MerchantProductGroups($shop_id: Int, $show_on_main: Boolean) {
                merchantProductGroups(shop_id: $shop_id, show_on_main: $show_on_main) {
                    data {
                        id
                        show_on_main
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
                'show_on_main' => true,
            ]);

        $response->assertJsonPath('data.merchantProductGroups.paginatorInfo.total', 1);
    }

    /** @test */
    public function it_can_filter_by_show_on_search()
    {
        $this->createGroup(['show_on_search' => true]);
        $this->createGroup(['show_on_search' => false]);
        $this->createGroup(['show_on_search' => false]);

        $query = '
            query MerchantProductGroups($shop_id: Int, $show_on_search: Boolean) {
                merchantProductGroups(shop_id: $shop_id, show_on_search: $show_on_search) {
                    data {
                        id
                        show_on_search
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
                'show_on_search' => true,
            ]);

        $response->assertJsonPath('data.merchantProductGroups.paginatorInfo.total', 1);
    }

    /** @test */
    public function it_can_create_group_with_show_on_search()
    {
        $mutation = '
            mutation CreateMerchantProductGroup($input: CreateMerchantProductGroupInput!) {
                createMerchantProductGroup(input: $input) {
                    id
                    show_on_search
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'title' => 'Группа на поиске',
                    'show_on_search' => true,
                ],
            ]);

        $response->assertJsonPath('data.createMerchantProductGroup.show_on_search', true);
        $this->assertDatabaseHas('merchant_product_groups', [
            'id' => $response->json('data.createMerchantProductGroup.id'),
            'show_on_search' => true,
        ]);
    }

    /** @test */
    public function it_preserves_product_order_in_group()
    {
        $group = $this->createGroup();

        $mutation = '
            mutation UpdateMerchantProductGroup($id: Int!, $input: UpdateMerchantProductGroupInput!) {
                updateMerchantProductGroup(id: $id, input: $input) {
                    id
                    products {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $group->id,
                'input' => [
                    'product_ids' => [$this->product2->id, $this->product1->id],
                ],
            ]);

        $group->refresh();
        $products = $group->products()->orderByPivot('sort_order')->get();

        $this->assertEquals($this->product2->id, $products[0]->id);
        $this->assertEquals($this->product1->id, $products[1]->id);
    }

    /** @test */
    public function it_cannot_add_products_from_another_shop_on_update()
    {
        $group = $this->createGroup();

        $anotherShop = $this->createShop();
        $productFromAnotherShop = $this->createProduct($anotherShop->id);

        $mutation = '
            mutation UpdateMerchantProductGroup($id: Int!, $input: UpdateMerchantProductGroupInput!) {
                updateMerchantProductGroup(id: $id, input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $group->id,
                'input' => [
                    'product_ids' => [$productFromAnotherShop->id],
                ],
            ]);

        $response->assertGraphQLValidationKeys(['input.product_ids']);
    }

    /** @test */
    public function it_can_create_group_without_products()
    {
        $mutation = '
            mutation CreateMerchantProductGroup($input: CreateMerchantProductGroupInput!) {
                createMerchantProductGroup(input: $input) {
                    id
                    title
                    products_count
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'title' => 'Пустая группа',
                ],
            ]);

        $response->assertJson([
            'data' => [
                'createMerchantProductGroup' => [
                    'title' => 'Пустая группа',
                    'products_count' => 0,
                ],
            ],
        ]);
    }
}
