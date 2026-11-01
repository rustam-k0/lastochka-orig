<?php

namespace Tests\Feature\Product;

use App\Filters\ProductFilter;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductAdultOnlyFilterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_adult_only_defaults_to_false(): void
    {
        $shop = Shop::firstOrFail();

        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Товар без флага ' . uniqid();
        $product->save();

        $this->assertFalse($product->fresh()->adult_only);
    }

    public function test_adult_only_persists_as_boolean(): void
    {
        $shop = Shop::firstOrFail();

        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Товар 18+ ' . uniqid();
        $product->adult_only = true;
        $product->save();

        $this->assertTrue($product->fresh()->adult_only);
    }

    public function test_filter_scopes_products_by_adult_only(): void
    {
        $shop = Shop::firstOrFail();

        $restricted = new Product();
        $restricted->shop_id = $shop->id;
        $restricted->title = 'Вино ' . uniqid();
        $restricted->adult_only = true;
        $restricted->save();

        $regular = new Product();
        $regular->shop_id = $shop->id;
        $regular->title = 'Молоко ' . uniqid();
        $regular->adult_only = false;
        $regular->save();

        $filtered = (new ProductFilter(Product::query(), ['adult_only' => true]))->apply()->get();

        $this->assertTrue($filtered->contains('id', $restricted->id));
        $this->assertFalse($filtered->contains('id', $regular->id));
    }
}
