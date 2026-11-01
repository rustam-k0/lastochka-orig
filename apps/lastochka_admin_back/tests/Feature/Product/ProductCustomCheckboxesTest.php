<?php

namespace Tests\Feature\Product;

use App\Models\AdminSetting;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductCustomCheckboxesTest extends TestCase
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
            'Product' => [
                ['field' => 'is_hit', 'label' => 'Хит продаж', 'enabled' => true, 'custom' => true, 'filterable' => true],
            ],
        ]), 'json');

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
    }

    /** @test */
    public function product_update_stores_sanitized_custom_checkboxes(): void
    {
        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int, $shop_id: Int, $price: Float, $custom_checkboxes: String) {
                ProductUpdate(id: $id, shop_id: $shop_id, price: $price, custom_checkboxes: $custom_checkboxes) {
                    code
                    message
                }
            }
        ', [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 100.0,
            'custom_checkboxes' => json_encode(['is_hit' => true, 'unknown' => true]),
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $map = json_decode($this->product->fresh()->custom_checkboxes, true);
        $this->assertSame(['is_hit' => true], $map);
    }

    /** @test */
    public function product_update_without_key_keeps_stored_value(): void
    {
        $this->product->update(['custom_checkboxes' => json_encode((object) ['is_hit' => true])]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int, $shop_id: Int, $price: Float) {
                ProductUpdate(id: $id, shop_id: $shop_id, price: $price) { code message }
            }
        ', [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 200.0,
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $map = json_decode($this->product->fresh()->custom_checkboxes, true);
        $this->assertSame(['is_hit' => true], $map);
    }

    /** @test */
    public function product_type_exposes_custom_checkboxes_string(): void
    {
        $this->product->update(['custom_checkboxes' => json_encode((object) ['is_hit' => true])]);

        $response = $this->actingAs($this->user)->graphQL('
            query($id: Int) {
                admin_products_item(id: $id) { id custom_checkboxes }
            }
        ', ['id' => $this->product->id]);

        $this->assertSame(['is_hit' => true], json_decode($response->json('data.admin_products_item.custom_checkboxes'), true));
    }
}
