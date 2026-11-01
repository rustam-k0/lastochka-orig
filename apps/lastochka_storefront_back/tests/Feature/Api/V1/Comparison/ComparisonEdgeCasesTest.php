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

final class ComparisonEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_item_returns_422_for_product_without_category(): void
    {
        $user = User::factory()->create();
        // Product created with no category (no inCategory() call → empty category_product pivot)
        $product = Product::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/comparisons/items', ['productId' => $product->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['productId']);

        $this->assertDatabaseCount('comparisons', 0);
    }

    public function test_add_item_returns_422_for_missing_product(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/comparisons/items', ['productId' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['productId']);
    }

    public function test_show_marks_soft_deleted_product_as_unavailable(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $alive = Product::factory()->inCategory($category)->create(['is_active' => true]);
        $deleted = Product::factory()->inCategory($category)->create(['is_active' => true]);

        $comparison = Comparison::factory()->for($user)->for($category)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($alive)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($deleted)->create();

        $deleted->delete(); // soft delete

        $response = $this->actingAs($user)
            ->getJson("/api/v1/comparisons/{$category->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data.products');

        $byId = collect($response->json('data.products'))->keyBy('id');
        $this->assertTrue($byId[$alive->id]['available']);
        $this->assertFalse($byId[$deleted->id]['available']);
    }

    public function test_show_marks_inactive_product_as_unavailable(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->inactive()->create();

        $comparison = Comparison::factory()->for($user)->for($category)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($product)->create();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/comparisons/{$category->id}");

        $response->assertOk()
            ->assertJsonPath('data.products.0.available', false);
    }

    public function test_remove_item_returns_404_for_unknown_product(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/v1/comparisons/items/999999')
            ->assertNotFound();
    }

    public function test_clear_returns_404_for_missing_group(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/comparisons/{$category->id}")
            ->assertNotFound();
    }

    public function test_user_deletion_cascades_groups_and_items(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        $comparison = Comparison::factory()->for($user)->for($category)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($product)->create();

        $user->forceDelete();

        $this->assertDatabaseCount('comparisons', 0);
        $this->assertDatabaseCount('comparison_items', 0);
    }

    public function test_category_deletion_cascades_groups(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        $comparison = Comparison::factory()->for($user)->for($category)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($product)->create();

        $category->forceDelete();

        $this->assertDatabaseCount('comparisons', 0);
        $this->assertDatabaseCount('comparison_items', 0);
    }
}
