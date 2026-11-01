<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Comparison;

use App\Models\Category;
use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ComparisonCrossUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_does_not_see_groups_of_other_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $category = Category::factory()->create();

        $comparison = Comparison::factory()->for($owner)->for($category)->create();
        ComparisonItem::factory()
            ->for($comparison, 'comparison')
            ->for(Product::factory()->inCategory($category))
            ->create();

        $this->actingAs($intruder)
            ->getJson('/api/v1/comparisons')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_user_cannot_open_group_of_other_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $category = Category::factory()->create();

        Comparison::factory()->for($owner)->for($category)->create();

        $this->actingAs($intruder)
            ->getJson("/api/v1/comparisons/{$category->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_clear_group_of_other_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $category = Category::factory()->create();

        $comparison = Comparison::factory()->for($owner)->for($category)->create();
        ComparisonItem::factory()
            ->for($comparison, 'comparison')
            ->for(Product::factory()->inCategory($category))
            ->create();

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/comparisons/{$category->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('comparisons', 1);
        $this->assertDatabaseCount('comparison_items', 1);
    }

    public function test_user_cannot_remove_item_belonging_to_other_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        $comparison = Comparison::factory()->for($owner)->for($category)->create();
        ComparisonItem::factory()
            ->for($comparison, 'comparison')
            ->for($product)
            ->create();

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/comparisons/items/{$product->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('comparison_items', 1);
    }
}
