<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopCustomCheckboxesTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
        AdminSetting::where('key', 'entity_checkboxes')->delete();
        AdminSetting::setValue('entity_checkboxes', json_encode([
            'Shop' => [
                ['field' => 'is_flagship', 'label' => 'Флагманский', 'enabled' => true, 'custom' => true],
            ],
        ]), 'json');
    }

    private function makeShop(): Shop
    {
        // fresh(): в реальном ShopUpdate-мьютейшене $shop всегда получен через
        // Shop::find($id) — со всеми DB-дефолтными значениями колонок
        // (pickup_wait_*_hours и т.п.). Без refetch только что созданный
        // in-memory объект их не знает (Eloquent::create() не перечитывает
        // DB-дефолты), и fillInfo() ошибочно затирает их в null.
        return Shop::create([
            'title' => 'Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ])->fresh();
    }

    /** @test */
    public function fill_info_writes_custom_checkboxes_with_key(): void
    {
        $this->actingAs($this->user);
        $shop = $this->makeShop();

        (new ShopService())->fillInfo($shop, [
            'title' => $shop->title,
            'custom_checkboxes' => json_encode(['is_flagship' => true, 'unknown' => true]),
        ]);

        $this->assertSame(['is_flagship' => true], json_decode($shop->fresh()->custom_checkboxes, true));
    }

    /** @test */
    public function fill_info_without_key_keeps_stored_value(): void
    {
        $this->actingAs($this->user);
        $shop = $this->makeShop();
        $shop->update(['custom_checkboxes' => json_encode((object) ['is_flagship' => true])]);

        (new ShopService())->fillInfo($shop, ['title' => $shop->title]);

        $this->assertSame(['is_flagship' => true], json_decode($shop->fresh()->custom_checkboxes, true));
    }

    /** @test */
    public function shop_create_mutation_stores_custom_checkboxes(): void
    {
        $title = 'Магазин чекбоксов ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation($title: String, $type_id: Int, $custom_checkboxes: String) {
                ShopCreate(title: $title, type_id: $type_id, custom_checkboxes: $custom_checkboxes) { code message }
            }
        ', [
            'title' => $title,
            'type_id' => 1,
            'custom_checkboxes' => json_encode(['is_flagship' => true]),
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $shop = Shop::where('title', $title)->firstOrFail();
        $this->assertSame(['is_flagship' => true], json_decode($shop->custom_checkboxes, true));
    }
}
