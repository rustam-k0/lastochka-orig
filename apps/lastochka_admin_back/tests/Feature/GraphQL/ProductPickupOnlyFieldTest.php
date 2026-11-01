<?php

namespace Tests\Feature\GraphQL;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductPickupOnlyFieldTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::role('admin')->firstOrFail();
        $this->shop = Shop::firstOrFail();
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    /** @test */
    public function product_pickup_only_field_returns_true(): void
    {
        $product = new Product();
        $product->shop_id = $this->shop->id;
        $product->title = 'Вино ' . uniqid();
        $product->pickup_only = true;
        $product->save();

        $response = $this->actingAs($this->admin)->graphQL('
            query ($id: Int) {
                admin_products_item(id: $id) {
                    id
                    pickup_only
                }
            }
        ', ['id' => $product->id]);

        $response->assertJsonPath('data.admin_products_item.id', $product->id);
        $response->assertJsonPath('data.admin_products_item.pickup_only', true);
    }

    /** @test */
    public function product_pickup_only_field_defaults_to_false(): void
    {
        $product = new Product();
        $product->shop_id = $this->shop->id;
        $product->title = 'Молоко ' . uniqid();
        $product->save();

        $response = $this->actingAs($this->admin)->graphQL('
            query ($id: Int) {
                admin_products_item(id: $id) {
                    id
                    pickup_only
                }
            }
        ', ['id' => $product->id]);

        $response->assertJsonPath('data.admin_products_item.pickup_only', false);
    }

    /** @test */
    public function product_update_mutation_sets_pickup_only(): void
    {
        $product = new Product();
        $product->shop_id = $this->shop->id;
        $product->title = 'Пиво ' . uniqid();
        $product->save();

        $response = $this->actingAs($this->admin)->graphQL('
            mutation ($id: Int, $shop_id: Int, $price: Float, $pickup_only: Boolean) {
                ProductUpdate(id: $id, shop_id: $shop_id, price: $price, pickup_only: $pickup_only) {
                    code
                    message
                }
            }
        ', [
            'id' => $product->id,
            'shop_id' => $this->shop->id,
            'price' => 199.0,
            'pickup_only' => true,
        ]);

        $response->assertJsonPath('data.ProductUpdate.code', 200);
        $this->assertTrue($product->fresh()->pickup_only);
    }
}
