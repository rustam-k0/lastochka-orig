<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\MerchantBanner;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantBannerCustomCheckboxesTest extends TestCase
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
            'MerchantBanner' => [
                ['field' => 'is_seasonal', 'label' => 'Сезонный', 'enabled' => true, 'custom' => true],
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
    public function banner_create_stores_sanitized_custom_checkboxes(): void
    {
        $title = 'Баннер ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation($input: MerchantBannerCreateInput!) {
                merchantBannerCreate(input: $input) { id title }
            }
        ', [
            'input' => [
                'title' => $title,
                'shop_id' => $this->shop->id,
                'custom_checkboxes' => json_encode(['is_seasonal' => true, 'unknown' => true]),
            ],
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $banner = MerchantBanner::where('title', $title)->firstOrFail();
        $this->assertSame(['is_seasonal' => true], json_decode($banner->custom_checkboxes, true));
    }

    /** @test */
    public function banner_update_without_key_keeps_stored_value(): void
    {
        $banner = MerchantBanner::create([
            'title' => 'Баннер ' . uniqid(),
            'shop_id' => $this->shop->id,
            'is_active' => true,
            'position' => 0,
            'custom_checkboxes' => json_encode((object) ['is_seasonal' => true]),
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $input: MerchantBannerUpdateInput!) {
                merchantBannerUpdate(id: $id, input: $input) { id title }
            }
        ', ['id' => $banner->id, 'input' => ['title' => 'Новый ' . uniqid()]]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(['is_seasonal' => true], json_decode($banner->fresh()->custom_checkboxes, true));
    }
}
