<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\MerchantProductGroup;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantProductGroupCustomCheckboxesTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
        AdminSetting::where('key', 'entity_checkboxes')->delete();
        AdminSetting::setValue('entity_checkboxes', json_encode([
            'MerchantProductGroup' => [
                ['field' => 'is_bundle', 'label' => 'Комплект', 'enabled' => true, 'custom' => true],
            ],
        ]), 'json');
        $this->shop = Shop::create([
            'title' => 'Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    /** @test */
    public function group_create_stores_sanitized_custom_checkboxes(): void
    {
        $title = 'Группа ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation($input: CreateMerchantProductGroupInput!) {
                createMerchantProductGroup(input: $input) { id title }
            }
        ', [
            'input' => [
                'title' => $title,
                'shop_id' => $this->shop->id,
                'custom_checkboxes' => json_encode(['is_bundle' => true, 'unknown' => true]),
            ],
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $group = MerchantProductGroup::where('title', $title)->firstOrFail();
        $this->assertSame(['is_bundle' => true], json_decode($group->custom_checkboxes, true));
    }

    /** @test */
    public function group_update_without_key_keeps_stored_value(): void
    {
        $group = MerchantProductGroup::create([
            'shop_id' => $this->shop->id,
            'title' => 'Группа ' . uniqid(),
            'is_active' => true,
            'custom_checkboxes' => json_encode((object) ['is_bundle' => true]),
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $input: UpdateMerchantProductGroupInput!) {
                updateMerchantProductGroup(id: $id, input: $input) { id title }
            }
        ', ['id' => $group->id, 'input' => ['title' => 'Новая ' . uniqid()]]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(['is_bundle' => true], json_decode($group->fresh()->custom_checkboxes, true));
    }
}
