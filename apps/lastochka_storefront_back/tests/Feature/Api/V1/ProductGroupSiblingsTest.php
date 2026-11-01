<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\ProductGroup;
use App\Models\ProductGroupFilter;
use App\Models\ProductGroupFilterValue;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductGroupSiblingsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
    }

    public function test_siblings_returns_groups_with_same_non_style_filters(): void
    {
        // Фильтры
        $roomFilter = ProductGroupFilter::create(['name' => 'Комната', 'position' => 1, 'is_active' => true]);
        $sizeFilter = ProductGroupFilter::create(['name' => 'Площадь', 'position' => 2, 'is_active' => true]);
        $styleFilter = ProductGroupFilter::create(['name' => 'Стиль', 'position' => 3, 'is_active' => true]);

        $bathroom = ProductGroupFilterValue::create(['product_group_filter_id' => $roomFilter->id, 'value' => 'Ванная', 'position' => 1, 'is_active' => true]);
        $size8 = ProductGroupFilterValue::create(['product_group_filter_id' => $sizeFilter->id, 'value' => '8 м²', 'position' => 1, 'is_active' => true]);
        $minimalism = ProductGroupFilterValue::create(['product_group_filter_id' => $styleFilter->id, 'value' => 'Минимализм', 'position' => 1, 'is_active' => true]);
        $loft = ProductGroupFilterValue::create(['product_group_filter_id' => $styleFilter->id, 'value' => 'Лофт', 'position' => 2, 'is_active' => true]);
        $eco = ProductGroupFilterValue::create(['product_group_filter_id' => $styleFilter->id, 'value' => 'Эко-стиль', 'position' => 3, 'is_active' => true]);

        // Решения: 3 штуки для Ванной 8м² в разных стилях
        $current = ProductGroup::factory()->complexSolution()->for($this->store)->create(['is_active' => true]);
        $current->filterValues()->attach([$bathroom->id, $size8->id, $minimalism->id]);

        $sibling1 = ProductGroup::factory()->complexSolution()->for($this->store)->create(['is_active' => true]);
        $sibling1->filterValues()->attach([$bathroom->id, $size8->id, $loft->id]);

        $sibling2 = ProductGroup::factory()->complexSolution()->for($this->store)->create(['is_active' => true]);
        $sibling2->filterValues()->attach([$bathroom->id, $size8->id, $eco->id]);

        // Другая комната — не sibling
        $kitchen = ProductGroupFilterValue::create(['product_group_filter_id' => $roomFilter->id, 'value' => 'Кухня', 'position' => 2, 'is_active' => true]);
        $notSibling = ProductGroup::factory()->complexSolution()->for($this->store)->create(['is_active' => true]);
        $notSibling->filterValues()->attach([$kitchen->id, $size8->id, $loft->id]);

        // Обычная группа (не решение) с теми же фильтрами — не sibling
        $regularGroup = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);
        $regularGroup->filterValues()->attach([$bathroom->id, $size8->id, $loft->id]);

        $response = $this->getJson("/api/v1/product-groups/{$current->id}/siblings");

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($sibling1->id, $ids);
        $this->assertContains($sibling2->id, $ids);
        $this->assertNotContains($current->id, $ids);
        $this->assertNotContains($notSibling->id, $ids);
        $this->assertNotContains($regularGroup->id, $ids);
    }

    public function test_siblings_empty_when_no_filters(): void
    {
        $group = ProductGroup::factory()->for($this->store)->create(['is_active' => true]);

        $response = $this->getJson("/api/v1/product-groups/{$group->id}/siblings");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_siblings_404_for_inactive(): void
    {
        $group = ProductGroup::factory()->for($this->store)->create(['is_active' => false]);

        $response = $this->getJson("/api/v1/product-groups/{$group->id}/siblings");

        $response->assertNotFound();
    }
}
