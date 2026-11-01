<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Store;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupItem;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MenuSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_404_for_shop_type_store(): void
    {
        $store = Store::factory()->create(['type' => 'shop']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/menu-sections");

        $response->assertNotFound();
    }

    public function test_returns_404_for_nonexistent_store(): void
    {
        $response = $this->getJson('/api/v1/stores/999999/menu-sections');

        $response->assertNotFound();
    }

    public function test_returns_404_for_inactive_restaurant_store(): void
    {
        $store = Store::factory()->create(['type' => 'restaurant', 'is_active' => false]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/menu-sections");

        $response->assertNotFound();
    }

    public function test_returns_merged_and_sorted_sections(): void
    {
        $store = Store::factory()->create(['type' => 'restaurant']);

        $pizza = ProductGroup::factory()->onStorePage()->for($store)->create(['title' => 'Пицца', 'position' => 0]);
        $rolls = ProductGroup::factory()->onStorePage()->for($store)->create(['title' => 'Роллы', 'position' => 2]);

        $salads = Category::factory()->create(['name' => 'Салаты']);
        $salads->stores()->attach($store->id, ['position' => 1, 'is_active' => true]);

        $drinks = Category::factory()->create(['name' => 'Напитки']);
        $drinks->stores()->attach($store->id, ['position' => 3, 'is_active' => true]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/menu-sections");

        $response->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.type', 'product_group')
            ->assertJsonPath('data.0.id', $pizza->id)
            ->assertJsonPath('data.0.title', 'Пицца')
            ->assertJsonPath('data.0.sortOrder', 1)
            ->assertJsonPath('data.1.type', 'category')
            ->assertJsonPath('data.1.id', $salads->id)
            ->assertJsonPath('data.1.title', 'Салаты')
            ->assertJsonPath('data.1.sortOrder', 2)
            ->assertJsonPath('data.2.type', 'product_group')
            ->assertJsonPath('data.2.id', $rolls->id)
            ->assertJsonPath('data.2.sortOrder', 3)
            ->assertJsonPath('data.3.type', 'category')
            ->assertJsonPath('data.3.id', $drinks->id)
            ->assertJsonPath('data.3.sortOrder', 4);
    }

    public function test_excludes_inactive_and_non_store_page_groups_and_categories(): void
    {
        $store = Store::factory()->create(['type' => 'restaurant']);

        ProductGroup::factory()->onStorePage()->for($store)->create(['title' => 'Видимая группа']);
        ProductGroup::factory()->for($store)->create(['title' => 'Не на странице магазина']); // show_on_store_page=false
        ProductGroup::factory()->onStorePage()->inactive()->for($store)->create(['title' => 'Неактивная группа']);

        $visibleCategory = Category::factory()->create(['name' => 'Видимая категория']);
        $visibleCategory->stores()->attach($store->id, ['position' => 0, 'is_active' => true]);

        $inactiveCategory = Category::factory()->inactive()->create(['name' => 'Неактивная категория']);
        $inactiveCategory->stores()->attach($store->id, ['position' => 1, 'is_active' => true]);

        $child = Category::factory()->create(['name' => 'Дочерняя категория']);
        $child->stores()->attach($store->id, ['position' => 2, 'is_active' => true]);
        $child->update(['parent_id' => $visibleCategory->id]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/menu-sections");

        $response->assertOk()->assertJsonCount(2, 'data');

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Видимая группа', $titles);
        $this->assertContains('Видимая категория', $titles);
        $this->assertNotContains('Не на странице магазина', $titles);
        $this->assertNotContains('Неактивная группа', $titles);
        $this->assertNotContains('Неактивная категория', $titles);
        $this->assertNotContains('Дочерняя категория', $titles);
    }

    public function test_section_does_not_expose_children_key(): void
    {
        $store = Store::factory()->create(['type' => 'restaurant']);

        $category = Category::factory()->create(['name' => 'Салаты']);
        $category->stores()->attach($store->id, ['position' => 0, 'is_active' => true]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/menu-sections");

        $response->assertOk();
        $this->assertArrayNotHasKey('children', $response->json('data.0'));
    }

    public function test_product_group_section_includes_its_products(): void
    {
        $store = Store::factory()->create(['type' => 'restaurant']);
        $group = ProductGroup::factory()->onStorePage()->for($store)->create(['title' => 'Пицца']);

        $product = Product::factory()->for($store)->create(['title' => 'Маргарита']);
        ProductGroupItem::factory()->for($group, 'productGroup')->for($product)->create(['position' => 0]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/menu-sections");

        $response->assertOk()
            ->assertJsonCount(1, 'data.0.products')
            ->assertJsonPath('data.0.products.0.id', $product->id)
            ->assertJsonPath('data.0.products.0.title', 'Маргарита');
    }

    public function test_category_section_includes_its_products(): void
    {
        $store = Store::factory()->create(['type' => 'restaurant']);
        $category = Category::factory()->create(['name' => 'Салаты']);
        $category->stores()->attach($store->id, ['position' => 0, 'is_active' => true]);

        $product = Product::factory()->for($store)->inCategory($category)->create(['title' => 'Цезарь']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/menu-sections");

        $response->assertOk()
            ->assertJsonCount(1, 'data.0.products')
            ->assertJsonPath('data.0.products.0.id', $product->id)
            ->assertJsonPath('data.0.products.0.title', 'Цезарь');
    }

    public function test_category_section_excludes_non_purchasable_products(): void
    {
        $store = Store::factory()->create(['type' => 'restaurant']);
        $category = Category::factory()->create(['name' => 'Салаты']);
        $category->stores()->attach($store->id, ['position' => 0, 'is_active' => true]);

        Product::factory()->for($store)->inCategory($category)->create(['title' => 'Неактивный', 'is_active' => false]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/menu-sections");

        $response->assertOk()->assertJsonCount(0, 'data.0.products');
    }

    public function test_products_are_capped_per_section(): void
    {
        $store = Store::factory()->create(['type' => 'restaurant']);
        $category = Category::factory()->create(['name' => 'Салаты']);
        $category->stores()->attach($store->id, ['position' => 0, 'is_active' => true]);

        $products = Product::factory(205)->for($store)->create();
        foreach ($products as $product) {
            $product->categories()->attach($category->id, ['position' => 0]);
        }

        $response = $this->getJson("/api/v1/stores/{$store->id}/menu-sections");

        $response->assertOk()
            ->assertJsonCount(200, 'data.0.products');
    }

    public function test_shop_type_store_categories_endpoint_still_works(): void
    {
        $store = Store::factory()->create(['type' => 'shop']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()->assertJsonCount(1, 'data');
    }
}
