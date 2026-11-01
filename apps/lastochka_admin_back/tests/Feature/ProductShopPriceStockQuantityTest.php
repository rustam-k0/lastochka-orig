<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Сетевой каталог: «Цены и остатки по магазинам» сохраняют дробный остаток
 * (база 1С, кг) через stock_quantity: Float. Старый quantity: Int! обрезал
 * 12.806 до 12 при правке одной только цены строки.
 */
class ProductShopPriceStockQuantityTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private User $user;
    private Shop $shop;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forever(\App\NetworkCatalog\Support\NetworkCatalogMode::CACHE_KEY, true);

        $this->user = $this->adminUser();
        $this->shop = Shop::create([
            'title' => 'Stock qty shop ' . uniqid(),
            'code' => 'stock-qty-' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
        $this->product = Product::create([
            'title' => 'Stock qty product ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::forget(\App\NetworkCatalog\Support\NetworkCatalogMode::CACHE_KEY);
        parent::tearDown();
    }

    private function remain(Shop $shop): float
    {
        return (float) Remain::where('product_id', $this->product->id)
            ->where('shop_id', $shop->id)
            ->value('quantity');
    }

    public function test_save_keeps_fractional_stock_quantity(): void
    {
        $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
          mutation ($product_id: Int!, $shop_id: Int!, $price: Float!, $stock_quantity: Float) {
            ProductShopPriceSave(product_id: $product_id, shop_id: $shop_id, price: $price, stock_quantity: $stock_quantity) { value }
          }
        ', [
            'product_id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 178.2,
            'stock_quantity' => 12.806,
        ])->assertJson(['data' => ['ProductShopPriceSave' => ['value' => 178.2]]]);

        $this->assertSame(12.806, $this->remain($this->shop));
    }

    public function test_apply_to_all_keeps_fractional_stock_quantity(): void
    {
        $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
          mutation ($product_id: Int!, $price: Float!, $stock_quantity: Float) {
            ProductShopPricesApplyToAll(product_id: $product_id, price: $price, stock_quantity: $stock_quantity)
          }
        ', [
            'product_id' => $this->product->id,
            'price' => 178.2,
            'stock_quantity' => 0.828,
        ])->assertJsonMissingPath('errors');

        $this->assertSame(0.828, $this->remain($this->shop));
    }

    public function test_save_requires_some_stock_quantity(): void
    {
        $response = $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
          mutation ($product_id: Int!, $shop_id: Int!, $price: Float!) {
            ProductShopPriceSave(product_id: $product_id, shop_id: $shop_id, price: $price) { value }
          }
        ', [
            'product_id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 178.2,
        ]);

        $this->assertNotEmpty($response->json('errors'));
    }
}
