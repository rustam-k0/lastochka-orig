<?php

namespace Tests\Feature\GraphQL;

use App\Filters\ProductFilter;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Галочки товара «Новинка» (is_new) и «Успей купить» (is_hurry_up):
 * поле GraphQL, сохранение через ProductUpdate, фильтр списка товаров.
 */
class ProductNewHurryUpFlagsTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->adminUser();
        $this->shop = Shop::firstOrFail();
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    private function makeProduct(string $title, array $flags = []): Product
    {
        $product = new Product();
        $product->shop_id = $this->shop->id;
        $product->title = $title . ' ' . uniqid();
        foreach ($flags as $field => $value) {
            $product->{$field} = $value;
        }
        $product->save();

        return $product;
    }

    /** @test */
    public function flags_default_to_false(): void
    {
        $product = $this->makeProduct('Молоко');

        $response = $this->actingAs($this->admin)->graphQL('
            query ($id: Int) {
                admin_products_item(id: $id) {
                    id
                    is_new
                    is_hurry_up
                }
            }
        ', ['id' => $product->id]);

        $response->assertJsonPath('data.admin_products_item.is_new', false);
        $response->assertJsonPath('data.admin_products_item.is_hurry_up', false);
    }

    /** @test */
    public function product_update_mutation_sets_and_clears_flags(): void
    {
        $product = $this->makeProduct('Сыр');

        $mutation = '
            mutation ($id: Int, $shop_id: Int, $price: Float, $is_new: Boolean, $is_hurry_up: Boolean) {
                ProductUpdate(id: $id, shop_id: $shop_id, price: $price, is_new: $is_new, is_hurry_up: $is_hurry_up) {
                    code
                    message
                }
            }
        ';

        $this->actingAs($this->admin)->graphQL($mutation, [
            'id' => $product->id,
            'shop_id' => $this->shop->id,
            'price' => 199.0,
            'is_new' => true,
            'is_hurry_up' => true,
        ])->assertJsonPath('data.ProductUpdate.code', 200);

        $fresh = $product->fresh();
        $this->assertTrue($fresh->is_new);
        $this->assertTrue($fresh->is_hurry_up);

        $this->actingAs($this->admin)->graphQL($mutation, [
            'id' => $product->id,
            'shop_id' => $this->shop->id,
            'price' => 199.0,
            'is_new' => false,
        ])->assertJsonPath('data.ProductUpdate.code', 200);

        $fresh = $product->fresh();
        $this->assertFalse($fresh->is_new);
        $this->assertTrue($fresh->is_hurry_up, 'не переданный флаг не должен меняться');
    }

    /** @test */
    public function admin_products_paginate_filters_by_is_new_and_is_hurry_up(): void
    {
        $marker = 'ФЛАГ' . uniqid();
        $new = $this->makeProduct($marker . ' новинка', ['is_new' => true]);
        $hurry = $this->makeProduct($marker . ' успей', ['is_hurry_up' => true]);
        $plain = $this->makeProduct($marker . ' обычный');

        $query = '
            query ($shop_id: Int, $title: String, $is_new: Boolean, $is_hurry_up: Boolean) {
                admin_products_paginate(shop_id: $shop_id, title: $title, is_new: $is_new, is_hurry_up: $is_hurry_up, first: 50) {
                    data { id }
                }
            }
        ';

        $ids = function (array $vars) use ($query, $marker) {
            $response = $this->actingAs($this->admin)->graphQL($query, array_merge([
                'shop_id' => $this->shop->id,
                'title' => $marker,
            ], $vars));

            return collect($response->json('data.admin_products_paginate.data'))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        };

        $this->assertEqualsCanonicalizing([$new->id], $ids(['is_new' => true]));
        $this->assertEqualsCanonicalizing([$hurry->id], $ids(['is_hurry_up' => true]));
        $this->assertEqualsCanonicalizing([$hurry->id, $plain->id], $ids(['is_new' => false]));
        $this->assertEqualsCanonicalizing([$new->id, $hurry->id, $plain->id], $ids([]));
    }

    /** @test */
    public function product_filter_skips_null_values(): void
    {
        $marker = 'NULL' . uniqid();
        $new = $this->makeProduct($marker, ['is_new' => true]);
        $plain = $this->makeProduct($marker);

        $filtered = (new ProductFilter(Product::query()->where('title', 'like', "$marker%"), [
            'is_new' => null,
            'is_hurry_up' => null,
        ]))->apply()->pluck('id');

        $this->assertTrue($filtered->contains($new->id));
        $this->assertTrue($filtered->contains($plain->id));
    }
}
