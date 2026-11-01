<?php

namespace Tests\Feature\NetworkCatalog;

use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Задача 7 плана «Сетевой каталог»: admin_front (Task 19) рендерит таблицу
 * «Цены и остатки по магазинам» из карточки товара — нужны prices { shop_id value }
 * рядом с уже существующим remains { shop_id quantity }.
 */
class AdminProductPricesByShopTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::role('admin')->firstOrFail();
    }

    /** @test */
    public function test_product_prices_expose_shop_id(): void
    {
        $shopA = Shop::create(['title' => 'A', 'code' => 'gql-a-' . uniqid()]);
        $shopB = Shop::create(['title' => 'B', 'code' => 'gql-b-' . uniqid()]);
        $product = Product::withoutEvents(fn () => Product::create([
            'title' => 'GQL товар',
            'articul' => 'GQL-' . uniqid(),
            'shop_id' => $shopA->id,
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
        ]));
        foreach ([[$shopA, 100], [$shopB, 130]] as [$shop, $value]) {
            Price::withoutEvents(fn () => (new Price())->forceFill([
                'product_id' => $product->id,
                'shop_id' => $shop->id,
                'type_id' => Type::PRICE_DEFAULT,
                'value' => $value,
                'step' => 1,
            ])->save());
        }

        $response = $this->actingAs($this->admin)->graphQL(/** @lang GraphQL */ '
            query ($id: Int) {
                admin_products_item(id: $id) {
                    id
                    prices { shop_id value }
                    remains { shop_id quantity }
                }
            }
        ', ['id' => $product->id]);

        $prices = collect($response->json('data.admin_products_item.prices'));
        $this->assertCount(2, $prices);
        $this->assertTrue($prices->contains(fn ($p) => (int) $p['shop_id'] === $shopA->id && (float) $p['value'] === 100.0));
        $this->assertTrue($prices->contains(fn ($p) => (int) $p['shop_id'] === $shopB->id && (float) $p['value'] === 130.0));
    }
}
