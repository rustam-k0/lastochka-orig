<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\ProductGroup;

use App\Models\ProductGroup;
use App\Models\ProductGroupFilter;
use App\Models\ProductGroupFilterValue;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductGroupComplexSolutionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
    }

    // ==================== Placement-выборки исключают решения ====================

    public function test_home_placement_excludes_complex_solutions(): void
    {
        ProductGroup::factory()->onHome()->for($this->store)->create(['title' => 'Обычная на главной']);
        // Решение с ошибочно включённым placement-флагом (данные до инварианта админки)
        ProductGroup::factory()->complexSolution()->onHome()->for($this->store)->create(['title' => 'Решение']);

        $response = $this->getJson('/api/v1/product-groups?placement=home');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Обычная на главной');
    }

    public function test_search_placement_excludes_complex_solutions(): void
    {
        ProductGroup::factory()->onSearch()->for($this->store)->create(['title' => 'Обычная на поиске']);
        ProductGroup::factory()->complexSolution()->onSearch()->for($this->store)->create(['title' => 'Решение']);

        $response = $this->getJson('/api/v1/product-groups?placement=search');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Обычная на поиске');
    }

    public function test_cart_page_excludes_complex_solutions(): void
    {
        ProductGroup::factory()->for($this->store)->create(['title' => 'Корзинная', 'is_cart_page' => true]);
        ProductGroup::factory()->complexSolution()->for($this->store)->create(['title' => 'Решение', 'is_cart_page' => true]);

        $response = $this->getJson('/api/v1/product-groups?isCartPage=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Корзинная');
    }

    // ==================== Фасетный каталог отдаёт только решения ====================

    public function test_filter_values_returns_only_complex_solutions(): void
    {
        $value = $this->seedFilterValue();

        $solution = ProductGroup::factory()->complexSolution()->for($this->store)->create(['title' => 'Решение']);
        $solution->filterValues()->attach([$value->id]);

        // Обычная группа с теми же фильтрами (legacy-данные) — не попадает в каталог
        $regular = ProductGroup::factory()->for($this->store)->create(['title' => 'Обычная']);
        $regular->filterValues()->attach([$value->id]);

        $response = $this->getJson("/api/v1/product-groups?filterValues={$value->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Решение')
            ->assertJsonPath('data.0.isComplexSolution', true);
    }

    // ==================== Resource ====================

    public function test_resource_exposes_is_complex_solution_flag(): void
    {
        $solution = ProductGroup::factory()->complexSolution()->for($this->store)->create();
        $regular = ProductGroup::factory()->for($this->store)->create();

        $this->getJson("/api/v1/product-groups/{$solution->id}")
            ->assertOk()
            ->assertJsonPath('data.isComplexSolution', true);

        $this->getJson("/api/v1/product-groups/{$regular->id}")
            ->assertOk()
            ->assertJsonPath('data.isComplexSolution', false);
    }

    private function seedFilterValue(): ProductGroupFilterValue
    {
        $filter = ProductGroupFilter::create([
            'name' => 'Комната',
            'position' => 0,
            'is_active' => true,
        ]);

        return ProductGroupFilterValue::create([
            'product_group_filter_id' => $filter->id,
            'value' => 'Ванная',
            'position' => 0,
            'is_active' => true,
        ]);
    }
}
