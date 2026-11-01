<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\ProductGroup;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupFilter;
use App\Models\ProductGroupFilterValue;
use App\Models\ProductGroupItem;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductGroupTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Get Product Groups (Home) ====================

    public function test_get_home_product_groups(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        ProductGroup::factory()->onHome()->for($store1)->create(['position' => 1, 'title' => 'Первая']);
        ProductGroup::factory()->onHome()->for($store2)->create(['position' => 0, 'title' => 'Вторая']);
        ProductGroup::factory()->for($store1)->create(['title' => 'Не на главной']);

        $response = $this->getJson('/api/v1/product-groups?placement=home');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Вторая')
            ->assertJsonPath('data.1.title', 'Первая');
    }

    public function test_get_home_product_groups_returns_only_active(): void
    {
        $store = Store::factory()->create();
        ProductGroup::factory()->onHome()->for($store)->create(['title' => 'Активная']);
        ProductGroup::factory()->onHome()->inactive()->for($store)->create(['title' => 'Неактивная']);

        $response = $this->getJson('/api/v1/product-groups?placement=home');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Активная');
    }

    public function test_get_home_product_groups_includes_store(): void
    {
        $store = Store::factory()->create(['name' => 'Тестовый магазин']);
        ProductGroup::factory()->onHome()->for($store)->create();

        $response = $this->getJson('/api/v1/product-groups?placement=home');

        $response->assertOk()
            ->assertJsonPath('data.0.store.name', 'Тестовый магазин');
    }

    public function test_get_home_product_groups_requires_placement(): void
    {
        $response = $this->getJson('/api/v1/product-groups');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['placement']);
    }

    public function test_get_home_product_groups_rejects_invalid_placement(): void
    {
        $response = $this->getJson('/api/v1/product-groups?placement=store_page');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['placement']);
    }

    // ==================== Get Product Groups (Search) ====================

    public function test_get_search_product_groups(): void
    {
        $store = Store::factory()->create();

        ProductGroup::factory()->onSearch()->for($store)->create(['position' => 1, 'title' => 'На поиске']);
        ProductGroup::factory()->onHome()->for($store)->create(['title' => 'Только на главной']);
        ProductGroup::factory()->for($store)->create(['title' => 'Обычная']);

        $response = $this->getJson('/api/v1/product-groups?placement=search');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'На поиске');
    }

    public function test_get_search_product_groups_returns_only_active(): void
    {
        $store = Store::factory()->create();
        ProductGroup::factory()->onSearch()->for($store)->create(['title' => 'Активная']);
        ProductGroup::factory()->onSearch()->inactive()->for($store)->create(['title' => 'Неактивная']);

        $response = $this->getJson('/api/v1/product-groups?placement=search');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Активная');
    }

    // ==================== Faceted filter (filterValues without placement) ====================

    public function test_filter_values_without_placement_does_not_require_placement(): void
    {
        // Spec 05-complex-solutions-catalog.md §5: на последнем шаге каталога
        // комплексных решений мобилка шлёт только filterValues, без placement.
        $store = Store::factory()->create();
        [$v1, $v2] = $this->seedFilterValues();

        $matchingGroup = ProductGroup::factory()->complexSolution()->for($store)->create(['title' => 'Под фильтр']);
        $matchingGroup->filterValues()->attach([$v1->id, $v2->id]);

        $partialGroup = ProductGroup::factory()->complexSolution()->for($store)->create(['title' => 'Частичное совпадение']);
        $partialGroup->filterValues()->attach([$v1->id]);

        $response = $this->getJson("/api/v1/product-groups?filterValues={$v1->id},{$v2->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Под фильтр');
    }

    public function test_filter_values_uses_AND_filtering(): void
    {
        $store = Store::factory()->create();
        [$v1, $v2, $v3] = $this->seedFilterValues();

        $allThree = ProductGroup::factory()->complexSolution()->for($store)->create(['title' => 'Все три']);
        $allThree->filterValues()->attach([$v1->id, $v2->id, $v3->id]);

        $onlyTwo = ProductGroup::factory()->complexSolution()->for($store)->create(['title' => 'Только два']);
        $onlyTwo->filterValues()->attach([$v1->id, $v2->id]);

        $response = $this->getJson("/api/v1/product-groups?filterValues={$v1->id},{$v2->id},{$v3->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Все три');
    }

    public function test_filter_values_with_invalid_format_returns_422(): void
    {
        $this->getJson('/api/v1/product-groups?filterValues=abc,def')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filterValues']);

        $this->getJson('/api/v1/product-groups?filterValues=1,,3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filterValues']);
    }

    public function test_filter_values_empty_result_when_no_match(): void
    {
        Store::factory()->create();
        [$v1] = $this->seedFilterValues();

        $response = $this->getJson("/api/v1/product-groups?filterValues={$v1->id}");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_placement_home_with_filter_values_intersection(): void
    {
        $store = Store::factory()->create();
        [$v1] = $this->seedFilterValues();

        $homeMatching = ProductGroup::factory()->complexSolution()->onHome()->for($store)->create(['title' => 'Home + filter']);
        $homeMatching->filterValues()->attach([$v1->id]);

        $homeOnly = ProductGroup::factory()->onHome()->for($store)->create(['title' => 'Только home']);

        $filterOnly = ProductGroup::factory()->complexSolution()->for($store)->create(['title' => 'Только filter']);
        $filterOnly->filterValues()->attach([$v1->id]);

        $response = $this->getJson("/api/v1/product-groups?placement=home&filterValues={$v1->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Home + filter');
    }

    /**
     * @return array<int, ProductGroupFilterValue>
     */
    private function seedFilterValues(): array
    {
        $filter = ProductGroupFilter::create([
            'name' => 'Стиль',
            'position' => 0,
            'is_active' => true,
        ]);

        return [
            ProductGroupFilterValue::create([
                'product_group_filter_id' => $filter->id,
                'value' => 'Минимализм',
                'position' => 0,
                'is_active' => true,
            ]),
            ProductGroupFilterValue::create([
                'product_group_filter_id' => $filter->id,
                'value' => 'Лофт',
                'position' => 1,
                'is_active' => true,
            ]),
            ProductGroupFilterValue::create([
                'product_group_filter_id' => $filter->id,
                'value' => 'Скандинавский',
                'position' => 2,
                'is_active' => true,
            ]),
        ];
    }

    // ==================== Get Store Product Groups ====================

    public function test_get_store_product_groups(): void
    {
        $store = Store::factory()->create();
        $group1 = ProductGroup::factory()->for($store)->create(['position' => 1, 'title' => 'Первая группа']);
        $group2 = ProductGroup::factory()->for($store)->create(['position' => 0, 'title' => 'Вторая группа']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Вторая группа')
            ->assertJsonPath('data.1.title', 'Первая группа');
    }

    public function test_get_store_product_groups_returns_only_active(): void
    {
        $store = Store::factory()->create();
        ProductGroup::factory()->for($store)->create(['title' => 'Активная']);
        ProductGroup::factory()->inactive()->for($store)->create(['title' => 'Неактивная']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Активная');
    }

    public function test_get_store_product_groups_does_not_return_other_stores_groups(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        ProductGroup::factory()->for($store1)->create();
        ProductGroup::factory()->for($store2)->create();

        $response = $this->getJson("/api/v1/stores/{$store1->id}/product-groups");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_get_store_product_groups_includes_products_count(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $group = ProductGroup::factory()->for($store)->create();

        $products = Product::factory(3)->for($store)->inCategory($category)->create();
        foreach ($products as $index => $product) {
            ProductGroupItem::factory()
                ->for($group, 'productGroup')
                ->for($product)
                ->create(['position' => $index]);
        }

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups");

        $response->assertOk()
            ->assertJsonPath('data.0.productsCount', 3);
    }

    // ==================== Filter by Placement ====================

    public function test_filter_by_placement_store_page(): void
    {
        $store = Store::factory()->create();
        ProductGroup::factory()->onStorePage()->for($store)->create(['title' => 'На странице магазина']);
        ProductGroup::factory()->inCatalog()->for($store)->create(['title' => 'В каталоге']);
        ProductGroup::factory()->for($store)->create(['title' => 'Обычная']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups?placement=store_page");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'На странице магазина');
    }

    public function test_filter_by_placement_catalog(): void
    {
        $store = Store::factory()->create();
        ProductGroup::factory()->onStorePage()->for($store)->create(['title' => 'На странице магазина']);
        ProductGroup::factory()->inCatalog()->for($store)->create(['title' => 'В каталоге']);
        ProductGroup::factory()->for($store)->create(['title' => 'Обычная']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups?placement=catalog");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'В каталоге');
    }

    public function test_filter_by_placement_all(): void
    {
        $store = Store::factory()->create();
        ProductGroup::factory()->onStorePage()->for($store)->create();
        ProductGroup::factory()->inCatalog()->for($store)->create();
        ProductGroup::factory()->for($store)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups?placement=all");

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_with_products_loads_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $group = ProductGroup::factory()->for($store)->create();

        $products = Product::factory(3)->for($store)->inCategory($category)->create();
        foreach ($products as $index => $product) {
            ProductGroupItem::factory()
                ->for($group, 'productGroup')
                ->for($product)
                ->create(['position' => $index]);
        }

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups?withProducts=true");

        $response->assertOk()
            ->assertJsonPath('data.0.productsCount', 3)
            ->assertJsonCount(3, 'data.0.products');
    }

    public function test_with_products_excludes_out_of_stock_when_preorders_disabled(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $group = ProductGroup::factory()->for($store)->create();

        $inStock = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Available banana',
            'quantity' => 100,
            'quantity_step' => 1,
        ]);
        $outOfStock = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Sold-out banana',
            'quantity' => -200,
            'quantity_step' => 500,
        ]);

        foreach ([$inStock, $outOfStock] as $i => $product) {
            ProductGroupItem::factory()
                ->for($group, 'productGroup')
                ->for($product)
                ->create(['position' => $i]);
        }

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups?withProducts=true");

        $response->assertOk()
            ->assertJsonCount(1, 'data.0.products')
            ->assertJsonPath('data.0.products.0.id', $inStock->id);
    }

    public function test_with_products_respects_limit(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $group = ProductGroup::factory()->for($store)->create();

        $products = Product::factory(10)->for($store)->inCategory($category)->create();
        foreach ($products as $index => $product) {
            ProductGroupItem::factory()
                ->for($group, 'productGroup')
                ->for($product)
                ->create(['position' => $index]);
        }

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups?withProducts=true&productsLimit=5");

        $response->assertOk()
            ->assertJsonPath('data.0.productsCount', 10)
            ->assertJsonCount(5, 'data.0.products');
    }

    // ==================== Get Product Group ====================

    public function test_get_product_group(): void
    {
        $store = Store::factory()->create();
        $group = ProductGroup::factory()->for($store)->create([
            'title' => 'Хиты продаж',
            'meta' => ['subtitle' => 'Самые популярные'],
        ]);

        $response = $this->getJson("/api/v1/product-groups/{$group->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $group->id)
            ->assertJsonPath('data.title', 'Хиты продаж')
            ->assertJsonPath('data.meta.subtitle', 'Самые популярные')
            ->assertJsonPath('data.productsCount', 0);
    }

    public function test_get_product_group_returns_404_for_inactive(): void
    {
        $store = Store::factory()->create();
        $group = ProductGroup::factory()->inactive()->for($store)->create();

        $response = $this->getJson("/api/v1/product-groups/{$group->id}");

        $response->assertNotFound();
    }

    public function test_get_product_group_returns_404_for_nonexistent(): void
    {
        $response = $this->getJson('/api/v1/product-groups/99999');

        $response->assertNotFound();
    }

    // ==================== Get Product Group Products ====================

    public function test_get_product_group_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $group = ProductGroup::factory()->for($store)->create();

        $products = Product::factory(3)->for($store)->inCategory($category)->create();
        foreach ($products as $index => $product) {
            ProductGroupItem::factory()
                ->for($group, 'productGroup')
                ->for($product)
                ->create(['position' => $index]);
        }

        $response = $this->getJson("/api/v1/product-groups/{$group->id}/products");

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'data',
                'total',
            ])
            ->assertJsonPath('total', 3)
            ->assertJsonPath('hasMore', false)
            ->assertJsonCount(3, 'data');
    }

    public function test_get_product_group_products_sorted_by_position(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $group = ProductGroup::factory()->for($store)->create();

        $product1 = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Третий']);
        $product2 = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Первый']);
        $product3 = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Второй']);

        ProductGroupItem::factory()->for($group, 'productGroup')->for($product1)->create(['position' => 2]);
        ProductGroupItem::factory()->for($group, 'productGroup')->for($product2)->create(['position' => 0]);
        ProductGroupItem::factory()->for($group, 'productGroup')->for($product3)->create(['position' => 1]);

        $response = $this->getJson("/api/v1/product-groups/{$group->id}/products");

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'Первый')
            ->assertJsonPath('data.1.title', 'Второй')
            ->assertJsonPath('data.2.title', 'Третий');
    }

    public function test_get_product_group_products_equal_positions_ordered_by_id(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $group = ProductGroup::factory()->for($store)->create();

        $first = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Бета']);
        $second = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Альфа']);
        $third = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Гамма']);

        // position одинаковый у всех (дефолт 0 при массовой привязке)
        foreach ([$third, $first, $second] as $product) {
            ProductGroupItem::factory()->for($group, 'productGroup')->for($product)->create(['position' => 0]);
        }

        $response = $this->getJson("/api/v1/product-groups/{$group->id}/products");

        $response->assertOk();
        $this->assertEquals(
            [$first->id, $second->id, $third->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_group_products_by_slug_popular_sort_breaks_ties_by_title_then_id(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $group = ProductGroup::factory()->for($store)->create(['slug' => 'hits']);
        $order = Order::factory()->create(['store_id' => $store->id]);

        $beta = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Бета']);
        $gamma = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Гамма']);
        $alpha = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Альфа']);
        $popular = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Омега']);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $popular->id]);

        foreach ([$beta, $gamma, $alpha, $popular] as $index => $product) {
            ProductGroupItem::factory()->for($group, 'productGroup')->for($product)->create(['position' => $index]);
        }

        $response = $this->getJson("/api/v1/stores/{$store->id}/product-groups/slug/hits/products?sort=popular");

        $response->assertOk();
        $this->assertEquals(
            ['Омега', 'Альфа', 'Бета', 'Гамма'],
            collect($response->json('data'))->pluck('title')->all(),
        );
    }

    public function test_get_product_group_products_pagination(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $group = ProductGroup::factory()->for($store)->create();

        $products = Product::factory(25)->for($store)->inCategory($category)->create();
        foreach ($products as $index => $product) {
            ProductGroupItem::factory()
                ->for($group, 'productGroup')
                ->for($product)
                ->create(['position' => $index]);
        }

        $response = $this->getJson("/api/v1/product-groups/{$group->id}/products?perPage=10");

        $response->assertOk()
            ->assertJsonPath('total', 25)
            ->assertJsonPath('hasMore', true)
            ->assertJsonCount(10, 'data');

        // Вторая страница
        $response2 = $this->getJson("/api/v1/product-groups/{$group->id}/products?perPage=10&page=2");

        $response2->assertOk()
            ->assertJsonPath('hasMore', true)
            ->assertJsonCount(10, 'data');

        // Третья страница (последняя)
        $response3 = $this->getJson("/api/v1/product-groups/{$group->id}/products?perPage=10&page=3");

        $response3->assertOk()
            ->assertJsonPath('hasMore', false)
            ->assertJsonCount(5, 'data');
    }

    public function test_get_product_group_products_returns_404_for_inactive_group(): void
    {
        $store = Store::factory()->create();
        $group = ProductGroup::factory()->inactive()->for($store)->create();

        $response = $this->getJson("/api/v1/product-groups/{$group->id}/products");

        $response->assertNotFound();
    }

    public function test_get_product_group_products_per_page_max_100(): void
    {
        $store = Store::factory()->create();
        $group = ProductGroup::factory()->for($store)->create();

        // Запрос с perPage > 100 должен ограничиться до 100
        $response = $this->getJson("/api/v1/product-groups/{$group->id}/products?perPage=150");

        $response->assertOk();
    }
}
