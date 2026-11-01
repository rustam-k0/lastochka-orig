<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_root_categories(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create([
            'name' => 'Молочные продукты',
            'position' => 1,
        ]);
        Category::factory()->forStore($store)->create([
            'name' => 'Хлеб',
            'position' => 2,
        ]);
        Category::factory()->forStore($store, ['is_active' => false])->create([
            'name' => 'Неактивная',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'data' => [
                    '*' => ['id', 'name', 'slug', 'children'],
                ],
                'total',
            ])
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.name', 'Молочные продукты')
            ->assertJsonPath('data.1.name', 'Хлеб');
    }

    public function test_get_child_categories(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create([
            'name' => 'Молочные продукты',
        ]);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Молоко',
            'position' => 1,
        ]);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Сыры',
            'position' => 2,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$parent->id}/children");

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.name', 'Молоко')
            ->assertJsonPath('data.1.name', 'Сыры');
    }

    public function test_include_children(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create([
            'name' => 'Молочные продукты',
        ]);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Молоко',
            'position' => 1,
        ]);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Сыры',
            'position' => 2,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=true");

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Молочные продукты')
            ->assertJsonCount(2, 'data.0.children')
            ->assertJsonPath('data.0.children.0.name', 'Молоко')
            ->assertJsonPath('data.0.children.1.name', 'Сыры');
    }

    public function test_inactive_children_not_included(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create([
            'name' => 'Молочные продукты',
        ]);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Молоко',
        ]);
        Category::factory()->childOf($parent)->forStore($store, ['is_active' => false])->create([
            'name' => 'Неактивная',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=true");

        $response->assertOk()
            ->assertJsonCount(1, 'data.0.children')
            ->assertJsonPath('data.0.children.0.name', 'Молоко');
    }

    public function test_categories_sorted_by_position_then_name(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store, ['position' => 1])->create([
            'name' => 'B Категория',
            'position' => 1,
        ]);
        Category::factory()->forStore($store, ['position' => 1])->create([
            'name' => 'A Категория',
            'position' => 1,
        ]);
        Category::factory()->forStore($store, ['position' => 0])->create([
            'name' => 'C Категория',
            'position' => 0,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'C Категория')
            ->assertJsonPath('data.1.name', 'A Категория')
            ->assertJsonPath('data.2.name', 'B Категория');
    }

    public function test_category_visible_in_multiple_stores(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        $category = Category::factory()->forStore($store1)->create([
            'name' => 'Бакалея',
        ]);
        $category->stores()->syncWithoutDetaching([
            $store2->id => ['position' => 0, 'is_active' => true],
        ]);

        $response1 = $this->getJson("/api/v1/stores/{$store1->id}/categories");
        $response2 = $this->getJson("/api/v1/stores/{$store2->id}/categories");

        $response1->assertOk()->assertJsonPath('data.0.name', 'Бакалея');
        $response2->assertOk()->assertJsonPath('data.0.name', 'Бакалея');
    }

    public function test_category_hidden_per_store_via_pivot(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        $category = Category::factory()->forStore($store1)->create([
            'name' => 'Бакалея',
        ]);
        $category->stores()->syncWithoutDetaching([
            $store2->id => ['position' => 0, 'is_active' => false],
        ]);

        $response1 = $this->getJson("/api/v1/stores/{$store1->id}/categories");
        $response2 = $this->getJson("/api/v1/stores/{$store2->id}/categories");

        $response1->assertOk()->assertJsonPath('total', 1);
        $response2->assertOk()->assertJsonPath('total', 0);
    }

    public function test_leaf_category_includes_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Напитки',
        ]);

        Product::factory()->count(3)->for($store)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1&includeProducts=1");

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Напитки')
            ->assertJsonCount(0, 'data.0.children')
            ->assertJsonCount(3, 'data.0.products');
    }

    public function test_products_follow_fork_default_when_flag_is_absent(): void
    {
        // Дефолт в коде — true: поле products читают клиенты не всех форков,
        // у Аладдина из него рисуется меню ресторана, и лёгкий ответ по умолчанию
        // оставил бы экран магазина пустым.
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'Напитки']);
        Product::factory()->count(3)->for($store)->inCategory($category)->create();

        $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Напитки')
            ->assertJsonCount(3, 'data.0.products');
    }

    public function test_products_are_not_returned_when_fork_default_is_off(): void
    {
        // Главный сценарий Ласточки: includeChildren=1 без includeProducts.
        // Вложенные товары давали 96 % веса ответа (2,29 МБ из 2,38 МБ на проде),
        // при том что её клиент их не парсит.
        config(['catalog.category_tree.include_products_default' => false]);

        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'Напитки']);
        Product::factory()->count(3)->for($store)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1");

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Напитки')
            ->assertJsonMissingPath('data.0.products');
    }

    public function test_explicit_zero_disables_products_even_when_default_is_on(): void
    {
        // Явный параметр сильнее дефолта в обе стороны: клиент форка с
        // include_products_default=true может попросить лёгкий ответ сам.
        config(['catalog.category_tree.include_products_default' => true]);

        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'Напитки']);
        Product::factory()->count(3)->for($store)->inCategory($category)->create();

        $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1&includeProducts=0")
            ->assertOk()
            ->assertJsonMissingPath('data.0.products');
    }

    public function test_products_are_returned_when_explicitly_requested(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'Напитки']);
        Product::factory()->count(3)->for($store)->inCategory($category)->create();

        $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1&includeProducts=1")
            ->assertOk()
            ->assertJsonCount(3, 'data.0.products');
    }

    public function test_products_require_include_children(): void
    {
        // Без includeChildren дерево не строится, значит и листьев нет —
        // includeProducts в одиночку не должен ничего подгружать.
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'Напитки']);
        Product::factory()->count(3)->for($store)->inCategory($category)->create();

        $this->getJson("/api/v1/stores/{$store->id}/categories?includeProducts=1")
            ->assertOk()
            ->assertJsonMissingPath('data.0.products');
    }

    public function test_leaf_category_returns_max_8_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Напитки',
        ]);

        Product::factory()->count(12)->for($store)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1&includeProducts=1");

        $response->assertOk()
            ->assertJsonCount(8, 'data.0.products');
    }

    public function test_leaf_products_are_limited_in_query_not_in_php(): void
    {
        // OOM-регрессия (GlitchTip STOREFRONT_BACK-1E): выборка товаров для
        // leaf-категорий должна ограничиваться лимитом на стороне SQL.
        // Старый код грузил ВСЕ товары всех leaf-категорий в память
        // и обрезал take(8) уже в PHP — на каталоге 13k+ товаров это 128M OOM.
        $store = Store::factory()->create();
        $bigCategory = Category::factory()->forStore($store)->create(['name' => 'Большая']);
        $smallCategory = Category::factory()->forStore($store)->create(['name' => 'Маленькая']);

        Product::factory()->count(30)->for($store)->inCategory($bigCategory)->create();
        Product::factory()->count(5)->for($store)->inCategory($smallCategory)->create();

        $hydrated = 0;
        Product::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1&includeProducts=1");

        $response->assertOk();

        $data = collect($response->json('data'));
        $this->assertCount(8, $data->firstWhere('name', 'Большая')['products']);
        $this->assertCount(5, $data->firstWhere('name', 'Маленькая')['products']);

        // 8 (лимит большой категории) + 5 (вся маленькая) = 13; всё сверх этого —
        // значит в память гидрируется больше, чем уходит в ответ
        $this->assertLessThanOrEqual(
            13,
            $hydrated,
            "Гидрировано {$hydrated} Product-моделей — выборка не ограничена лимитом на категорию",
        );
    }

    public function test_category_with_children_does_not_include_products(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create([
            'name' => 'Молочные продукты',
        ]);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Молоко',
        ]);

        Product::factory()->count(3)->for($store)->inCategory($parent)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1");

        $response->assertOk()
            ->assertJsonCount(1, 'data.0.children')
            ->assertJsonMissing(['products']);
    }

    public function test_leaf_category_excludes_inactive_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Напитки',
        ]);

        Product::factory()->count(2)->for($store)->inCategory($category)->create();
        Product::factory()->inactive()->for($store)->inCategory($category)->create([
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1&includeProducts=1");

        $response->assertOk()
            ->assertJsonCount(2, 'data.0.products');
    }

    public function test_leaf_category_excludes_products_from_other_stores(): void
    {
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Напитки',
        ]);

        Product::factory()->count(2)->for($store)->inCategory($category)->create();
        Product::factory()->for($otherStore)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1&includeProducts=1");

        $response->assertOk()
            ->assertJsonCount(2, 'data.0.products');
    }

    public function test_root_categories_have_is_leaf_field(): void
    {
        $store = Store::factory()->create();
        $leaf = Category::factory()->forStore($store)->create(['name' => 'Leaf']);
        $parent = Category::factory()->forStore($store)->create(['name' => 'Parent']);
        Category::factory()->childOf($parent)->forStore($store)->create(['name' => 'Child']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk();

        $data = collect($response->json('data'));
        $leafData = $data->firstWhere('name', 'Leaf');
        $parentData = $data->firstWhere('name', 'Parent');

        $this->assertTrue($leafData['isLeaf']);
        $this->assertFalse($parentData['isLeaf']);
    }

    public function test_price_range_returns_min_and_max(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        Product::factory()->for($store)->inCategory($category)->create(['price' => 100]);
        Product::factory()->for($store)->inCategory($category)->create(['price' => 500]);
        Product::factory()->for($store)->inCategory($category)->create(['price' => 3000]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/price-range");

        $response->assertOk()
            ->assertJsonPath('min', 100)
            ->assertJsonPath('max', 3000);
    }

    public function test_price_range_excludes_inactive_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        Product::factory()->for($store)->inCategory($category)->create(['price' => 200]);
        Product::factory()->for($store)->inCategory($category)->create(['price' => 800]);
        Product::factory()->inactive()->for($store)->inCategory($category)->create(['price' => 50]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/price-range");

        $response->assertOk()
            ->assertJsonPath('min', 200)
            ->assertJsonPath('max', 800);
    }

    public function test_price_range_empty_category(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/price-range");

        $response->assertOk()
            ->assertJsonPath('min', 0)
            ->assertJsonPath('max', 0);
    }

    public function test_children_have_is_leaf_field(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create(['name' => 'Parent']);
        $child = Category::factory()->childOf($parent)->forStore($store)->create(['name' => 'Child']);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$parent->id}/children");

        $response->assertOk()
            ->assertJsonPath('data.0.isLeaf', true);
    }

    public function test_category_with_only_inactive_children_is_leaf(): void
    {
        // children_count должен считать только видимых детей: категория,
        // у которой все дети деактивированы, для клиента — лист
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create(['name' => 'Родитель']);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Скрытая',
            'is_active' => false,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.isLeaf', true);
    }

    public function test_category_with_active_child_is_not_leaf(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create(['name' => 'Родитель']);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Видимая',
        ]);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Скрытая',
            'is_active' => false,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.isLeaf', false);
    }

    public function test_inactive_children_excluded_from_children_endpoint(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create(['name' => 'Родитель']);
        Category::factory()->childOf($parent)->forStore($store)->create(['name' => 'Видимая']);
        Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Скрытая',
            'is_active' => false,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$parent->id}/children");

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Видимая');
    }

    public function test_category_returns_background_image(): void
    {
        $store = Store::factory()->create();
        Category::factory()
            ->forStore($store)
            ->withBackground('https://cdn.example.com/categories/backgrounds/bg.png')
            ->create(['name' => 'С фоном', 'position' => 1]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'С фоном')
            ->assertJsonPath('data.0.backgroundImage.path', 'https://cdn.example.com/categories/backgrounds/bg.png');
    }

    public function test_category_background_image_null_when_absent(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create([
            'name' => 'Без фона',
            'background_image_path' => null,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.backgroundImage', null);
    }

    public function test_leaf_category_with_products_returns_tag(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Выпечка',
            'tag_text' => 'Готовим свежие каждый день',
            'tag_color' => '#FF5733',
        ]);
        Product::factory()->for($store)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.tagText', 'Готовим свежие каждый день')
            ->assertJsonPath('data.0.tagColor', '#FF5733');
    }

    public function test_parent_category_tag_is_hidden(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create([
            'name' => 'Родитель',
            'tag_text' => 'Не должен показаться',
            'tag_color' => '#FF5733',
        ]);
        Category::factory()->childOf($parent)->forStore($store)->create(['name' => 'Ребёнок']);
        Product::factory()->for($store)->inCategory($parent)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.tagText', null)
            ->assertJsonPath('data.0.tagColor', null);
    }

    public function test_leaf_category_without_products_tag_is_hidden(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create([
            'name' => 'Пустая',
            'tag_text' => 'Тег',
            'tag_color' => '#FF5733',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.tagText', null)
            ->assertJsonPath('data.0.tagColor', null);
    }

    public function test_child_leaf_tag_visible_in_tree(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create(['name' => 'Родитель']);
        $child = Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Лист',
            'tag_text' => 'Свежее',
            'tag_color' => '#00AA00',
        ]);
        Product::factory()->for($store)->inCategory($child)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories?includeChildren=1");

        $response->assertOk()
            ->assertJsonPath('data.0.children.0.tagText', 'Свежее')
            ->assertJsonPath('data.0.children.0.tagColor', '#00AA00');
    }

    public function test_children_endpoint_returns_tag(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create(['name' => 'Родитель']);
        $child = Category::factory()->childOf($parent)->forStore($store)->create([
            'name' => 'Лист',
            'tag_text' => 'Свежее',
            'tag_color' => '#00AA00',
        ]);
        Product::factory()->for($store)->inCategory($child)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$parent->id}/children");

        $response->assertOk()
            ->assertJsonPath('data.0.tagText', 'Свежее')
            ->assertJsonPath('data.0.tagColor', '#00AA00');
    }

    public function test_leaf_tag_hidden_when_products_belong_to_other_store(): void
    {
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Чужие товары',
            'tag_text' => 'Тег',
            'tag_color' => '#FF5733',
        ]);
        Product::factory()->for($otherStore)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.tagText', null)
            ->assertJsonPath('data.0.tagColor', null);
    }

    public function test_leaf_tag_hidden_when_products_not_purchasable(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Непокупаемые',
            'tag_text' => 'Тег',
            'tag_color' => '#FF5733',
        ]);
        Product::factory()->inactive()->for($store)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories");

        $response->assertOk()
            ->assertJsonPath('data.0.tagText', null)
            ->assertJsonPath('data.0.tagColor', null);
    }
}
