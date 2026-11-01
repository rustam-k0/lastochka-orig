<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promocode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromocodeMatchesProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_product_when_any_pivot_category_matches(): void
    {
        $promoCat = Category::factory()->create();
        $otherCat = Category::factory()->create();

        $promocode = Promocode::factory()->create(['binding_type' => 'categories']);
        $promocode->categories()->attach($promoCat->id);

        $product = Product::factory()->create();
        $product->categories()->attach([
            $otherCat->id => ['position' => 0],
            $promoCat->id => ['position' => 1],
        ]);

        $this->assertTrue($promocode->matchesProduct($product->fresh('categories')));
    }

    public function test_does_not_match_when_no_pivot_category_overlap(): void
    {
        $promoCat = Category::factory()->create();
        $unrelatedCat = Category::factory()->create();

        $promocode = Promocode::factory()->create(['binding_type' => 'categories']);
        $promocode->categories()->attach($promoCat->id);

        $product = Product::factory()->create();
        $product->categories()->attach($unrelatedCat->id, ['position' => 0]);

        $this->assertFalse($promocode->matchesProduct($product->fresh('categories')));
    }

    public function test_does_not_match_when_product_has_no_categories(): void
    {
        $promoCat = Category::factory()->create();
        $promocode = Promocode::factory()->create(['binding_type' => 'categories']);
        $promocode->categories()->attach($promoCat->id);

        $product = Product::factory()->create();

        $this->assertFalse($promocode->matchesProduct($product->fresh('categories')));
    }
}
