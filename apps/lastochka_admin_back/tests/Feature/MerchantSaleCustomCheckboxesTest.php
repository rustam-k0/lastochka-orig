<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\MerchantSale;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantSaleCustomCheckboxesTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
        AdminSetting::where('key', 'entity_checkboxes')->delete();
        AdminSetting::setValue('entity_checkboxes', json_encode([
            'MerchantSale' => [
                ['field' => 'is_flash', 'label' => 'Флеш-распродажа', 'enabled' => true, 'custom' => true],
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
        $this->product = Product::create([
            'title' => 'Product ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
    }

    /** @test */
    public function sale_create_stores_sanitized_custom_checkboxes(): void
    {
        $title = 'Акция ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation($input: CreateMerchantSaleInput!) {
                createMerchantSale(input: $input) { id title }
            }
        ', [
            'input' => [
                'title' => $title,
                'shop_id' => $this->shop->id,
                'discount_percent' => 10,
                'starts_at' => now()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'),
                'product_ids' => [$this->product->id],
                'custom_checkboxes' => json_encode(['is_flash' => true, 'unknown' => true]),
            ],
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $sale = MerchantSale::where('title', $title)->firstOrFail();
        $this->assertSame(['is_flash' => true], json_decode($sale->custom_checkboxes, true));
    }

    /** @test */
    public function sale_update_without_key_keeps_stored_value(): void
    {
        $sale = MerchantSale::create([
            'title' => 'Акция ' . uniqid(),
            'shop_id' => $this->shop->id,
            'discount_percent' => 10,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'is_active' => true,
            'custom_checkboxes' => json_encode((object) ['is_flash' => true]),
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $input: UpdateMerchantSaleInput!) {
                updateMerchantSale(id: $id, input: $input) { id title }
            }
        ', ['id' => $sale->id, 'input' => ['title' => 'Новая ' . uniqid()]]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(['is_flash' => true], json_decode($sale->fresh()->custom_checkboxes, true));
    }
}
