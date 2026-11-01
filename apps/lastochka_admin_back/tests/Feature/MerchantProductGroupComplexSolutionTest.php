<?php

namespace Tests\Feature;

use App\Models\MerchantProductGroup;
use App\Models\ProductGroupFilter;
use App\Models\ProductGroupFilterValue;
use App\Models\Shop;
use App\Models\User;
use App\Transformers\MerchantProductGroupEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantProductGroupComplexSolutionTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;
    protected ProductGroupFilterValue $filterValue;

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

        $filter = ProductGroupFilter::create([
            'name' => 'Комната ' . uniqid(),
            'position' => 0,
            'is_active' => true,
        ]);

        $this->filterValue = ProductGroupFilterValue::create([
            'product_group_filter_id' => $filter->id,
            'value' => 'Ванная',
            'position' => 0,
            'is_active' => true,
        ]);
    }

    protected function createGroup(array $attributes = []): MerchantProductGroup
    {
        return MerchantProductGroup::create(array_merge([
            'shop_id' => $this->shop->id,
            'title' => 'Test Group ' . uniqid(),
            'is_active' => true,
        ], $attributes));
    }

    /** @test */
    public function it_resets_placements_when_creating_complex_solution()
    {
        $mutation = '
            mutation CreateMerchantProductGroup($input: CreateMerchantProductGroupInput!) {
                createMerchantProductGroup(input: $input) {
                    id
                    is_complex_solution
                    show_on_main
                    show_on_merchant_page
                    show_in_merchant_catalog
                    show_on_search
                    is_recommended
                    is_cart_page
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'title' => 'Комплексное решение',
                    'is_complex_solution' => true,
                    'show_on_main' => true,
                    'show_on_merchant_page' => true,
                    'show_in_merchant_catalog' => true,
                    'show_on_search' => true,
                    'is_recommended' => true,
                    'is_cart_page' => true,
                    'filter_value_ids' => [$this->filterValue->id],
                ],
            ]);

        $response->assertJson([
            'data' => [
                'createMerchantProductGroup' => [
                    'is_complex_solution' => true,
                    'show_on_main' => false,
                    'show_on_merchant_page' => false,
                    'show_in_merchant_catalog' => false,
                    'show_on_search' => false,
                    'is_recommended' => false,
                    'is_cart_page' => false,
                ],
            ],
        ]);

        $groupId = $response->json('data.createMerchantProductGroup.id');

        $this->assertDatabaseHas('merchant_product_group_filter_values', [
            'merchant_product_group_id' => $groupId,
            'product_group_filter_value_id' => $this->filterValue->id,
        ]);
    }

    /** @test */
    public function it_ignores_filter_values_when_creating_regular_group()
    {
        $mutation = '
            mutation CreateMerchantProductGroup($input: CreateMerchantProductGroupInput!) {
                createMerchantProductGroup(input: $input) {
                    id
                    is_complex_solution
                    show_on_merchant_page
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'title' => 'Обычная группа',
                    'show_on_merchant_page' => true,
                    'filter_value_ids' => [$this->filterValue->id],
                ],
            ]);

        $response->assertJson([
            'data' => [
                'createMerchantProductGroup' => [
                    'is_complex_solution' => false,
                    'show_on_merchant_page' => true,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('merchant_product_group_filter_values', [
            'merchant_product_group_id' => $response->json('data.createMerchantProductGroup.id'),
        ]);
    }

    /** @test */
    public function it_resets_placements_when_switching_group_to_complex_solution()
    {
        $group = $this->createGroup([
            'show_on_main' => true,
            'show_on_merchant_page' => true,
            'show_on_search' => true,
            'is_recommended' => true,
            'is_cart_page' => true,
        ]);

        $mutation = '
            mutation UpdateMerchantProductGroup($id: Int!, $input: UpdateMerchantProductGroupInput!) {
                updateMerchantProductGroup(id: $id, input: $input) {
                    id
                    is_complex_solution
                }
            }
        ';

        $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $group->id,
                'input' => [
                    'is_complex_solution' => true,
                    'filter_value_ids' => [$this->filterValue->id],
                ],
            ])
            ->assertJsonPath('data.updateMerchantProductGroup.is_complex_solution', true);

        $this->assertDatabaseHas('merchant_product_groups', [
            'id' => $group->id,
            'is_complex_solution' => true,
            'show_on_main' => false,
            'show_on_merchant_page' => false,
            'show_in_merchant_catalog' => false,
            'show_on_search' => false,
            'is_recommended' => false,
            'is_cart_page' => false,
        ]);

        $this->assertDatabaseHas('merchant_product_group_filter_values', [
            'merchant_product_group_id' => $group->id,
            'product_group_filter_value_id' => $this->filterValue->id,
        ]);
    }

    /** @test */
    public function it_detaches_filter_values_when_switching_solution_back_to_regular_group()
    {
        $group = $this->createGroup(['is_complex_solution' => true]);
        $group->filterValues()->sync([$this->filterValue->id]);

        $mutation = '
            mutation UpdateMerchantProductGroup($id: Int!, $input: UpdateMerchantProductGroupInput!) {
                updateMerchantProductGroup(id: $id, input: $input) {
                    id
                    is_complex_solution
                    show_on_merchant_page
                }
            }
        ';

        $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $group->id,
                'input' => [
                    'is_complex_solution' => false,
                    'show_on_merchant_page' => true,
                ],
            ])
            ->assertJsonPath('data.updateMerchantProductGroup.is_complex_solution', false)
            ->assertJsonPath('data.updateMerchantProductGroup.show_on_merchant_page', true);

        $this->assertDatabaseMissing('merchant_product_group_filter_values', [
            'merchant_product_group_id' => $group->id,
        ]);
    }

    /** @test */
    public function it_keeps_placements_reset_when_updating_solution_without_flag()
    {
        $group = $this->createGroup(['is_complex_solution' => true]);

        $mutation = '
            mutation UpdateMerchantProductGroup($id: Int!, $input: UpdateMerchantProductGroupInput!) {
                updateMerchantProductGroup(id: $id, input: $input) {
                    id
                }
            }
        ';

        // Инвариант держится и без явной передачи is_complex_solution
        $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $group->id,
                'input' => [
                    'title' => 'Новое название',
                    'show_on_main' => true,
                    'is_cart_page' => true,
                ],
            ]);

        $this->assertDatabaseHas('merchant_product_groups', [
            'id' => $group->id,
            'is_complex_solution' => true,
            'show_on_main' => false,
            'is_cart_page' => false,
        ]);
    }

    /** @test */
    public function it_can_filter_groups_by_is_complex_solution()
    {
        $this->createGroup(['is_complex_solution' => true]);
        $this->createGroup(['is_complex_solution' => false]);
        $this->createGroup(['is_complex_solution' => false]);

        $query = '
            query MerchantProductGroups($shop_id: Int, $is_complex_solution: Boolean) {
                merchantProductGroups(shop_id: $shop_id, is_complex_solution: $is_complex_solution) {
                    data {
                        id
                        is_complex_solution
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
                'is_complex_solution' => true,
            ]);

        $response->assertJsonPath('data.merchantProductGroups.paginatorInfo.total', 1);
    }

    /** @test */
    public function it_includes_is_complex_solution_in_event_payload()
    {
        $group = $this->createGroup(['is_complex_solution' => true]);

        $payload = app(MerchantProductGroupEventTransformer::class)->toEvent($group->fresh());

        $this->assertTrue($payload['is_complex_solution']);

        $regular = $this->createGroup();

        $payload = app(MerchantProductGroupEventTransformer::class)->toEvent($regular->fresh());

        $this->assertFalse($payload['is_complex_solution']);
    }
}
