<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Comparison;

use App\Models\Category;
use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ComparisonFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_empty_when_no_groups(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/comparisons')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_index_returns_groups_sorted_by_created_at_desc(): void
    {
        $user = User::factory()->create();
        $catA = Category::factory()->create(['name' => 'Выключатели']);
        $catB = Category::factory()->create(['name' => 'Автоматы']);

        $older = Comparison::factory()->for($user)->for($catA)->create([
            'created_at' => now()->subHour(),
        ]);
        $newer = Comparison::factory()->for($user)->for($catB)->create([
            'created_at' => now(),
        ]);

        ComparisonItem::factory()->for($older, 'comparison')
            ->for(Product::factory()->inCategory($catA))->create();
        ComparisonItem::factory()->for($older, 'comparison')
            ->for(Product::factory()->inCategory($catA))->create();
        ComparisonItem::factory()->for($newer, 'comparison')
            ->for(Product::factory()->inCategory($catB))->create();

        $response = $this->actingAs($user)->getJson('/api/v1/comparisons');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.categoryId', $catB->id)
            ->assertJsonPath('data.0.categoryTitle', 'Автоматы')
            ->assertJsonPath('data.0.count', 1)
            ->assertJsonPath('data.1.categoryId', $catA->id)
            ->assertJsonPath('data.1.categoryTitle', 'Выключатели')
            ->assertJsonPath('data.1.count', 2);
    }

    public function test_show_returns_products_and_attribute_union(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create(['name' => 'Выключатели']);

        $first = Product::factory()->inCategory($category)->create([
            'title' => 'Выключатель Schneider',
            'price' => 1290,
            'weight' => 750,
            'measurement_unit' => 'liter',
            'created_at' => now()->subMinutes(10),
        ]);
        $second = Product::factory()->inCategory($category)->create([
            'title' => 'Выключатель Legrand',
            'price' => 990,
            'created_at' => now()->subMinutes(5),
        ]);

        ProductAttribute::create([
            'product_id' => $first->id, 'code' => 'color', 'name' => 'Цвет', 'value' => 'белый', 'position' => 1,
        ]);
        ProductAttribute::create([
            'product_id' => $first->id, 'code' => 'brand', 'name' => 'Бренд', 'value' => 'Schneider', 'position' => 2,
        ]);
        ProductAttribute::create([
            'product_id' => $second->id, 'code' => 'color', 'name' => 'Цвет', 'value' => 'чёрный', 'position' => 1,
        ]);
        ProductAttribute::create([
            'product_id' => $second->id, 'code' => 'ip_rating', 'name' => 'Степень защиты IP', 'value' => 'IP44', 'position' => 3,
        ]);

        $comparison = Comparison::factory()->for($user)->for($category)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($first)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($second)->create();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/comparisons/{$category->id}");

        $response->assertOk()
            ->assertJsonPath('data.categoryId', $category->id)
            ->assertJsonPath('data.categoryTitle', 'Выключатели')
            ->assertJsonCount(2, 'data.products')
            ->assertJsonCount(3, 'data.attributes')
            ->assertJsonPath('data.attributes.0.code', 'color')
            ->assertJsonPath('data.attributes.1.code', 'brand')
            ->assertJsonPath('data.attributes.2.code', 'ip_rating');

        $schneider = collect($response->json('data.products'))
            ->firstWhere('title', 'Выключатель Schneider');
        $this->assertSame(750, $schneider['weight']);
        $this->assertSame('liter', $schneider['measurementUnit']);
        $this->assertSame('л', $schneider['measurementUnitLabel']);
    }

    public function test_show_returns_404_when_group_missing(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/v1/comparisons/{$category->id}")
            ->assertNotFound();
    }

    public function test_add_item_creates_group_and_returns_count(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/comparisons/items', ['productId' => $product->id]);

        $response->assertCreated()
            ->assertJsonPath('data.categoryId', $category->id)
            ->assertJsonPath('data.productId', $product->id)
            ->assertJsonPath('data.count', 1);

        $this->assertDatabaseHas('comparisons', [
            'user_id' => $user->id,
            'category_id' => $category->id,
        ]);
        $this->assertDatabaseHas('comparison_items', [
            'product_id' => $product->id,
        ]);
    }

    public function test_add_item_is_idempotent_for_existing_pair(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/comparisons/items', ['productId' => $product->id])
            ->assertCreated();
        $this->actingAs($user)
            ->postJson('/api/v1/comparisons/items', ['productId' => $product->id])
            ->assertCreated()
            ->assertJsonPath('data.count', 1);

        $this->assertDatabaseCount('comparisons', 1);
        $this->assertDatabaseCount('comparison_items', 1);
    }

    public function test_add_item_pools_products_of_same_category_into_one_group(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $first = Product::factory()->inCategory($category)->create();
        $second = Product::factory()->inCategory($category)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/comparisons/items', ['productId' => $first->id])
            ->assertCreated();
        $response = $this->actingAs($user)
            ->postJson('/api/v1/comparisons/items', ['productId' => $second->id])
            ->assertCreated();

        $response->assertJsonPath('data.categoryId', $category->id)
            ->assertJsonPath('data.count', 2);

        $this->assertDatabaseCount('comparisons', 1);
        $this->assertDatabaseCount('comparison_items', 2);
    }

    public function test_remove_item_keeps_group_when_other_items_remain(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $first = Product::factory()->inCategory($category)->create();
        $second = Product::factory()->inCategory($category)->create();

        $comparison = Comparison::factory()->for($user)->for($category)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($first)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($second)->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/comparisons/items/{$first->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('comparisons', 1);
        $this->assertDatabaseMissing('comparison_items', [
            'comparison_id' => $comparison->id,
            'product_id' => $first->id,
        ]);
        $this->assertDatabaseHas('comparison_items', [
            'comparison_id' => $comparison->id,
            'product_id' => $second->id,
        ]);
    }

    public function test_remove_last_item_drops_empty_group(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        $comparison = Comparison::factory()->for($user)->for($category)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($product)->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/comparisons/items/{$product->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('comparisons', 0);
        $this->assertDatabaseCount('comparison_items', 0);
    }

    public function test_clear_group_removes_group_and_cascade_items(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $first = Product::factory()->inCategory($category)->create();
        $second = Product::factory()->inCategory($category)->create();

        $comparison = Comparison::factory()->for($user)->for($category)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($first)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($second)->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/comparisons/{$category->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('comparisons', 0);
        $this->assertDatabaseCount('comparison_items', 0);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/v1/comparisons')->assertUnauthorized();
        $this->postJson('/api/v1/comparisons/items', ['productId' => 1])->assertUnauthorized();
    }
}
