<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_categories_marked_show_on_main(): void
    {
        $store = Store::factory()->create();

        Category::factory()->forStore($store)->showOnMain()->create([
            'name' => 'Бакалея',
            'position' => 1,
        ]);
        Category::factory()->forStore($store)->create([
            'name' => 'Скрытая категория',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/home");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'position', 'image'],
                ],
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Бакалея');
    }

    public function test_excludes_inactive_categories(): void
    {
        $store = Store::factory()->create();

        Category::factory()->forStore($store)->showOnMain()->create([
            'name' => 'Активная',
        ]);
        Category::factory()->inactive()->forStore($store)->showOnMain()->create([
            'name' => 'Неактивная',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/home");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Активная');
    }

    public function test_excludes_categories_inactive_in_store_pivot(): void
    {
        $store = Store::factory()->create();

        Category::factory()->forStore($store)->showOnMain()->create([
            'name' => 'Видимая',
        ]);
        Category::factory()->forStore($store, ['is_active' => false])->showOnMain()->create([
            'name' => 'Отключена в магазине',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/home");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Видимая');
    }

    public function test_sorted_by_store_pivot_position(): void
    {
        $store = Store::factory()->create();

        Category::factory()->forStore($store, ['position' => 2])->showOnMain()->create([
            'name' => 'Вторая',
        ]);
        Category::factory()->forStore($store, ['position' => 0])->showOnMain()->create([
            'name' => 'Первая',
        ]);
        Category::factory()->forStore($store, ['position' => 1])->showOnMain()->create([
            'name' => 'Между',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/home");

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Первая')
            ->assertJsonPath('data.1.name', 'Между')
            ->assertJsonPath('data.2.name', 'Вторая');
    }

    public function test_returns_empty_when_no_categories_marked(): void
    {
        $store = Store::factory()->create();

        Category::factory()->forStore($store)->create([
            'name' => 'Обычная',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/home");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_does_not_return_categories_from_other_stores(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        Category::factory()->forStore($store1)->showOnMain()->create([
            'name' => 'Из первого магазина',
        ]);
        Category::factory()->forStore($store2)->showOnMain()->create([
            'name' => 'Из второго магазина',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store1->id}/categories/home");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Из первого магазина');
    }

    public function test_home_returns_tag_for_leaf_with_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->showOnMain()->create([
            'name' => 'Выпечка',
            'tag_text' => 'Готовим свежие каждый день',
            'tag_color' => '#FF5733',
        ]);
        Product::factory()->for($store)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/home");

        $response->assertOk()
            ->assertJsonPath('data.0.tagText', 'Готовим свежие каждый день')
            ->assertJsonPath('data.0.tagColor', '#FF5733');
    }
}
