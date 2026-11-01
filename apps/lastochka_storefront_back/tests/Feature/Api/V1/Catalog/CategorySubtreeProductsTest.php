<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Агрегация товаров по поддереву категорий: родительская категория отдаёт
 * товары самой категории + всех вложенных подкатегорий на любую глубину,
 * без дублей. Листовая категория — только свои товары (как раньше).
 */
final class CategorySubtreeProductsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Category $parent;

    private Category $child;

    private Category $grandchild;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->parent = Category::factory()->forStore($this->store)->create();
        $this->child = Category::factory()->forStore($this->store)->childOf($this->parent)->create();
        $this->grandchild = Category::factory()->forStore($this->store)->childOf($this->child)->create();
    }

    private function productsEndpoint(Category $category): string
    {
        return "/api/v1/stores/{$this->store->id}/categories/{$category->id}/products";
    }

    // ==================== /products ====================

    public function test_parent_category_returns_products_of_whole_subtree(): void
    {
        Product::factory()->for($this->store)->inCategory($this->parent)->create(['title' => 'On parent']);
        Product::factory()->for($this->store)->inCategory($this->child)->create(['title' => 'On child']);
        Product::factory()->for($this->store)->inCategory($this->grandchild)->create(['title' => 'On grandchild']);

        $response = $this->getJson($this->productsEndpoint($this->parent));

        $response->assertOk()->assertJsonPath('total', 3);
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['On parent', 'On child', 'On grandchild'], $titles);
    }

    public function test_product_in_multiple_subtree_categories_returned_once(): void
    {
        $product = Product::factory()->for($this->store)->inCategory($this->child)->create();
        $product->categories()->attach($this->grandchild->id);

        $response = $this->getJson($this->productsEndpoint($this->parent));

        $response->assertOk()->assertJsonPath('total', 1);
    }

    public function test_leaf_category_returns_only_own_products(): void
    {
        Product::factory()->for($this->store)->inCategory($this->grandchild)->create(['title' => 'Mine']);
        Product::factory()->for($this->store)->inCategory($this->child)->create(['title' => 'Parents own']);

        $sibling = Category::factory()->forStore($this->store)->childOf($this->child)->create();
        Product::factory()->for($this->store)->inCategory($sibling)->create(['title' => 'Siblings']);

        $response = $this->getJson($this->productsEndpoint($this->grandchild));

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('Mine', $response->json('data.0.title'));
    }

    public function test_subtree_does_not_include_other_category_branches(): void
    {
        Product::factory()->for($this->store)->inCategory($this->child)->create();

        $otherRoot = Category::factory()->forStore($this->store)->create();
        Product::factory()->for($this->store)->inCategory($otherRoot)->create();

        $response = $this->getJson($this->productsEndpoint($this->parent));

        $response->assertOk()->assertJsonPath('total', 1);
    }

    public function test_subtree_respects_store_filter(): void
    {
        Product::factory()->for($this->store)->inCategory($this->grandchild)->create();

        $otherStore = Store::factory()->create();
        Product::factory()->for($otherStore)->inCategory($this->grandchild)->create();

        $response = $this->getJson($this->productsEndpoint($this->parent));

        $response->assertOk()->assertJsonPath('total', 1);
    }

    public function test_sort_and_price_filters_apply_over_subtree(): void
    {
        Product::factory()->for($this->store)->inCategory($this->parent)->create(['price' => 50]);
        Product::factory()->for($this->store)->inCategory($this->child)->create(['price' => 300]);
        Product::factory()->for($this->store)->inCategory($this->grandchild)->create(['price' => 200]);
        Product::factory()->for($this->store)->inCategory($this->grandchild)->create(['price' => 500]);

        $response = $this->getJson($this->productsEndpoint($this->parent).'?price_min=100&price_max=400&sort=price_desc');

        $response->assertOk()->assertJsonPath('total', 2);
        $prices = collect($response->json('data'))->pluck('price')->all();
        $this->assertEquals([300, 200], $prices);
    }

    public function test_pagination_applies_over_subtree(): void
    {
        Product::factory()->count(2)->for($this->store)->inCategory($this->child)->create();
        Product::factory()->count(2)->for($this->store)->inCategory($this->grandchild)->create();

        $response = $this->getJson($this->productsEndpoint($this->parent).'?perPage=3');

        $response->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('hasMore', true);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_count_only_counts_subtree(): void
    {
        Product::factory()->for($this->store)->inCategory($this->child)->create(['price' => 300]);
        Product::factory()->for($this->store)->inCategory($this->grandchild)->create(['price' => 300]);

        $response = $this->getJson($this->productsEndpoint($this->parent).'?count_only=1');

        $response->assertOk();
        $this->assertSame(2, $response->json('total'));
    }

    // ==================== /price-range ====================

    public function test_price_range_for_parent_covers_subtree(): void
    {
        Product::factory()->for($this->store)->inCategory($this->parent)->create(['price' => 300]);
        Product::factory()->for($this->store)->inCategory($this->child)->create(['price' => 100]);
        Product::factory()->for($this->store)->inCategory($this->grandchild)->create(['price' => 900]);

        $response = $this->getJson("/api/v1/stores/{$this->store->id}/categories/{$this->parent->id}/price-range");

        $response->assertOk()
            ->assertJsonPath('min', 100)
            ->assertJsonPath('max', 900);
    }

    public function test_price_range_for_leaf_unchanged(): void
    {
        Product::factory()->for($this->store)->inCategory($this->child)->create(['price' => 100]);
        Product::factory()->for($this->store)->inCategory($this->grandchild)->create(['price' => 900]);

        $response = $this->getJson("/api/v1/stores/{$this->store->id}/categories/{$this->grandchild->id}/price-range");

        $response->assertOk()
            ->assertJsonPath('min', 900)
            ->assertJsonPath('max', 900);
    }

    // ==================== /filters ====================

    public function test_filters_for_parent_cover_subtree(): void
    {
        $onChild = Product::factory()->for($this->store)->inCategory($this->child)->create(['price' => 100]);
        $onGrandchild = Product::factory()->for($this->store)->inCategory($this->grandchild)->create(['price' => 900]);

        ProductAttribute::create([
            'product_id' => $onChild->id,
            'code' => 'manufacturer',
            'name' => 'Manufacturer',
            'value' => 'schneider',
            'attribute_value_id' => 1,
            'attribute_value_code' => 'schneider',
            'position' => 0,
        ]);
        ProductAttribute::create([
            'product_id' => $onGrandchild->id,
            'code' => 'manufacturer',
            'name' => 'Manufacturer',
            'value' => 'abb',
            'attribute_value_id' => 2,
            'attribute_value_code' => 'abb',
            'position' => 0,
        ]);

        $response = $this->getJson("/api/v1/stores/{$this->store->id}/categories/{$this->parent->id}/filters");

        $response->assertOk();
        $sections = collect($response->json('data'));

        $price = $sections->firstWhere('code', 'price');
        $this->assertNotNull($price);
        $this->assertSame(100, $price['min']);
        $this->assertSame(900, $price['max']);

        $manufacturer = $sections->firstWhere('code', 'manufacturer');
        $this->assertNotNull($manufacturer, 'Секция атрибута из товаров поддерева должна присутствовать');
        $this->assertEqualsCanonicalizing(
            ['schneider', 'abb'],
            collect($manufacturer['values'])->pluck('code')->all(),
        );
    }
}
