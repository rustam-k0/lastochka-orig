<?php

namespace Tests\Feature\Product;

use App\Filters\ProductFilter;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductPickupOnlyFilterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_pickup_only_defaults_to_false(): void
    {
        $shop = Shop::firstOrFail();

        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Товар без флага ' . uniqid();
        $product->save();

        $this->assertFalse($product->fresh()->pickup_only);
    }

    public function test_pickup_only_persists_as_boolean(): void
    {
        $shop = Shop::firstOrFail();

        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Товар только для самовывоза ' . uniqid();
        $product->pickup_only = true;
        $product->save();

        $this->assertTrue($product->fresh()->pickup_only);
    }

    public function test_filter_scopes_products_by_pickup_only(): void
    {
        $shop = Shop::firstOrFail();

        $restricted = new Product();
        $restricted->shop_id = $shop->id;
        $restricted->title = 'Вино ' . uniqid();
        $restricted->pickup_only = true;
        $restricted->save();

        $regular = new Product();
        $regular->shop_id = $shop->id;
        $regular->title = 'Молоко ' . uniqid();
        $regular->pickup_only = false;
        $regular->save();

        $filtered = (new ProductFilter(Product::query(), ['pickup_only' => true]))->apply()->get();

        $this->assertTrue($filtered->contains('id', $restricted->id));
        $this->assertFalse($filtered->contains('id', $regular->id));
    }
}
