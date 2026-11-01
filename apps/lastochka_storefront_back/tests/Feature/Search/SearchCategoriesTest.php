<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private SearchService $searchService;

    protected function setUp(): void
    {
        parent::setUp();

        // Observer отключаем — товары в этих тестах не индексируем
        Product::unsetEventDispatcher();

        $this->searchService = $this->app->make(SearchService::class);
    }

    public function test_search_returns_name_matched_categories(): void
    {
        $store = Store::factory()->create();
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        $dairy = Category::factory()->forStore($store)->create(['name' => 'Молоко, яйцо, масло']);
        $chemistry = Category::factory()->forStore($store)->create(['name' => 'Бытовая химия']);

        $result = $this->searchService->search(query: 'моло');

        $ids = $result['categories']->pluck('id')->all();

        $this->assertContains($milk->id, $ids);
        $this->assertContains($dairy->id, $ids);
        $this->assertNotContains($chemistry->id, $ids);
    }

    public function test_matching_is_case_insensitive_for_cyrillic(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'молочные продукты']);

        $result = $this->searchService->search(query: 'МОЛО');

        $this->assertContains($category->id, $result['categories']->pluck('id')->all());
    }

    public function test_multi_word_query_requires_all_words(): void
    {
        $store = Store::factory()->create();
        $dairy = Category::factory()->forStore($store)->create(['name' => 'Молоко, яйцо, масло']);
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        $result = $this->searchService->search(query: 'моло яйц');

        $ids = $result['categories']->pluck('id')->all();

        $this->assertContains($dairy->id, $ids);
        $this->assertNotContains($milk->id, $ids);
    }

    public function test_prefix_matches_sorted_first(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Растительное молоко']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        $result = $this->searchService->search(query: 'моло');

        $this->assertSame(
            ['Молоко', 'Растительное молоко'],
            $result['categories']->pluck('name')->all(),
        );
    }

    public function test_inactive_category_excluded(): void
    {
        $store = Store::factory()->create();
        Category::factory()->inactive()->forStore($store)->create(['name' => 'Молоко скрытое']);

        $result = $this->searchService->search(query: 'моло');

        $this->assertCount(0, $result['categories']);
    }

    public function test_inactive_pivot_excluded(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store, ['is_active' => false])->create(['name' => 'Молоко отвязанное']);

        $result = $this->searchService->search(query: 'моло');

        $this->assertCount(0, $result['categories']);
    }

    public function test_category_of_inactive_store_excluded(): void
    {
        $inactiveStore = Store::factory()->create(['is_active' => false]);
        Category::factory()->forStore($inactiveStore)->create(['name' => 'Молоко недоступное']);

        $result = $this->searchService->search(query: 'моло');

        $this->assertCount(0, $result['categories']);
    }

    public function test_categories_limit_zero_disables_section(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        $result = $this->searchService->search(query: 'моло', categoriesLimit: 0);

        $this->assertCount(0, $result['categories']);
    }

    public function test_categories_limit_is_part_of_cache_key(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко козье']);

        $first = $this->searchService->search(query: 'моло', categoriesLimit: 1);
        $second = $this->searchService->search(query: 'моло', categoriesLimit: 2);

        $this->assertCount(1, $first['categories']);
        $this->assertCount(2, $second['categories']);
    }

    public function test_parent_relation_loaded(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create(['name' => 'Молочные продукты']);
        $child = Category::factory()->childOf($parent)->forStore($store)->create(['name' => 'Молоко']);

        $result = $this->searchService->search(query: 'молоко');

        $found = $result['categories']->firstWhere('id', $child->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('parent'));
        $this->assertSame($parent->id, $found->parent->id);
    }
}
