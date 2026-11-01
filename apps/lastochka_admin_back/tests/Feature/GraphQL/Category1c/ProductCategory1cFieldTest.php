<?php

namespace Tests\Feature\GraphQL\Category1c;

use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Тест регрессии на маппинг GraphQL-поля `Product.category_1c` →
 * метод модели `category1c()` (без подчёркивания).
 *
 * Lighthouse по умолчанию выводит имя relation-метода из имени GraphQL-поля,
 * т.е. `category_1c` → `category_1c()`. У нас метод называется `category1c()`,
 * поэтому в схеме обязательно `@belongsTo(relation: "category1c")`. Без него
 * запрос падает с `Method ::category_1c does not exist`.
 *
 * См. spec §5.1 (Product.category_1c).
 */
class ProductCategory1cFieldTest extends TestCase
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
    public function product_category_1c_field_resolves_to_category_1c_model(): void
    {
        $cat1c = Category1c::factory()->create([
            'shop_id' => $this->shop->id,
        ]);
        // Product::factory()->create() пытается дернуть Shop::factory() —
        // у Shop нет HasFactory trait, поэтому строим Product вручную.
        $product = new Product();
        $product->shop_id = $this->shop->id;
        $product->title = 'Test product '.uniqid();
        $product->category_1c_id = $cat1c->id;
        $product->save();

        $response = $this->actingAs($this->admin)->graphQL('
            query ($id: Int) {
                admin_products_item(id: $id) {
                    id
                    category_1c { id name external_id }
                }
            }
        ', ['id' => $product->id]);

        $response->assertJsonPath('data.admin_products_item.id', $product->id);
        $response->assertJsonPath('data.admin_products_item.category_1c.id', $cat1c->id);
        $response->assertJsonPath('data.admin_products_item.category_1c.name', $cat1c->name);
        $response->assertJsonPath('data.admin_products_item.category_1c.external_id', $cat1c->external_id);
    }

    /** @test */
    public function product_category_1c_field_is_null_when_unset(): void
    {
        $product = new Product();
        $product->shop_id = $this->shop->id;
        $product->title = 'Test product '.uniqid();
        $product->category_1c_id = null;
        $product->save();

        $response = $this->actingAs($this->admin)->graphQL('
            query ($id: Int) {
                admin_products_item(id: $id) {
                    id
                    category_1c { id }
                }
            }
        ', ['id' => $product->id]);

        $response->assertJsonPath('data.admin_products_item.id', $product->id);
        $this->assertNull($response->json('data.admin_products_item.category_1c'));
    }
}
