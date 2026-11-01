<?php

namespace Tests\Feature\GraphQL;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * PLU-код товара из 1С, отдаётся read-only полем в GraphQL (для сборщика через delivery_back).
 * См. spec docs/superpowers/specs/2026-07-01-1c-plu-code-design.md
 */
class ProductPluFieldTest extends TestCase
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
    public function product_plu_field_returns_value(): void
    {
        $product = new Product();
        $product->shop_id = $this->shop->id;
        $product->title = 'Яблоки Голден '.uniqid();
        $product->plu = '4126';
        $product->save();

        $response = $this->actingAs($this->admin)->graphQL('
            query ($id: Int) {
                admin_products_item(id: $id) {
                    id
                    plu
                }
            }
        ', ['id' => $product->id]);

        $response->assertJsonPath('data.admin_products_item.id', $product->id);
        $response->assertJsonPath('data.admin_products_item.plu', '4126');
    }

    /** @test */
    public function product_plu_field_is_null_when_unset(): void
    {
        $product = new Product();
        $product->shop_id = $this->shop->id;
        $product->title = 'Товар без ПЛУ '.uniqid();
        $product->save();

        $response = $this->actingAs($this->admin)->graphQL('
            query ($id: Int) {
                admin_products_item(id: $id) {
                    id
                    plu
                }
            }
        ', ['id' => $product->id]);

        $response->assertJsonPath('data.admin_products_item.id', $product->id);
        $this->assertNull($response->json('data.admin_products_item.plu'));
    }
}
