<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Product;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RelatedProductsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Category $parent;
    private Category $category;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->parent = Category::factory()->forStore($this->store)->create();
        $this->category = Category::factory()->childOf($this->parent)->forStore($this->store)->create();
        $this->product = Product::factory()->for($this->store)->inCategory($this->category)->create([
            'quantity' => 10,
        ]);
    }

    public function test_related_returns_products_from_sibling_categories(): void
    {
        $sibling = Category::factory()->childOf($this->parent)->forStore($this->store)->create(['name' => 'Щитки']);
        Product::factory()->count(3)->for($this->store)->inCategory($sibling)->create([
            'quantity' => 5,
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/related");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Щитки')
            ->assertJsonCount(3, 'data.0.products');
    }

    public function test_related_returns_multiple_sibling_categories(): void
    {
        $sibling1 = Category::factory()->childOf($this->parent)->forStore($this->store)->create(['name' => 'Автоматика']);
        $sibling2 = Category::factory()->childOf($this->parent)->forStore($this->store)->create(['name' => 'Щитки']);

        Product::factory()->count(2)->for($this->store)->inCategory($sibling1)->create([
            'quantity' => 5,
        ]);
        Product::factory()->count(4)->for($this->store)->inCategory($sibling2)->create([
            'quantity' => 5,
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/related");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_related_excludes_current_category(): void
    {
        // No siblings, only current category
        $response = $this->getJson("/api/v1/products/{$this->product->id}/related");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_related_empty_when_no_parent(): void
    {
        // Product in root category (no parent_id)
        $rootCategory = Category::factory()->forStore($this->store)->create(['parent_id' => null]);
        $product = Product::factory()->for($this->store)->inCategory($rootCategory)->create([
            'quantity' => 10,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}/related");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_related_excludes_out_of_stock_products(): void
    {
        $sibling = Category::factory()->childOf($this->parent)->forStore($this->store)->create();
        Product::factory()->for($this->store)->inCategory($sibling)->create([
            'quantity' => 0,
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/related");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_related_excludes_inactive_sibling_categories(): void
    {
        $sibling = Category::factory()->childOf($this->parent)->forStore($this->store)->inactive()->create();
        Product::factory()->count(3)->for($this->store)->inCategory($sibling)->create([
            'quantity' => 5,
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/related");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_related_limits_products_per_category(): void
    {
        $sibling = Category::factory()->childOf($this->parent)->forStore($this->store)->create();
        Product::factory()->count(15)->for($this->store)->inCategory($sibling)->create([
            'quantity' => 5,
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/related?productsLimit=6");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(6, 'data.0.products');
    }

    public function test_related_returns_category_fields(): void
    {
        $sibling = Category::factory()->childOf($this->parent)->forStore($this->store)->create([
            'name' => 'УЗО',
            'slug' => 'uzo',
        ]);
        Product::factory()->for($this->store)->inCategory($sibling)->create([
            'quantity' => 5,
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/related");

        $response->assertOk()
            ->assertJsonPath('data.0.id', $sibling->id)
            ->assertJsonPath('data.0.name', 'УЗО')
            ->assertJsonPath('data.0.slug', 'uzo');
    }
}
