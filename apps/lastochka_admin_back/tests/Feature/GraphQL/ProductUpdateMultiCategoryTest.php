<?php

declare(strict_types=1);

namespace Tests\Feature\GraphQL;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductUpdateMultiCategoryTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    public function test_product_update_syncs_multiple_categories_with_position(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $shop = Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);

        [$c1, $c2, $c3] = [
            Category::factory()->create(['parent_id' => null]),
            Category::factory()->create(['parent_id' => null]),
            Category::factory()->create(['parent_id' => null]),
        ];

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int!, $shop_id: Int!, $category_ids: [Int!], $price: Float!) {
                ProductUpdate(id: $id, shop_id: $shop_id, category_ids: $category_ids, price: $price) {
                    code
                }
            }
        ', [
            'id' => $product->id,
            'shop_id' => $shop->id,
            'category_ids' => [$c2->id, $c3->id, $c1->id],
            'price' => 100,
        ])->assertJsonPath('data.ProductUpdate.code', 200);

        $pivot = \DB::table('category_product')
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->get();

        $this->assertCount(3, $pivot);
        $this->assertSame($c2->id, (int) $pivot[0]->category_id);
        $this->assertSame(0, (int) $pivot[0]->position);
        $this->assertSame($c3->id, (int) $pivot[1]->category_id);
        $this->assertSame(1, (int) $pivot[1]->position);
        $this->assertSame($c1->id, (int) $pivot[2]->category_id);
        $this->assertSame(2, (int) $pivot[2]->position);
    }

    public function test_product_update_rejects_non_leaf_category(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $shop = Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);

        $parent = Category::factory()->create(['parent_id' => null]);
        Category::factory()->create(['parent_id' => $parent->id]); // делает $parent НЕ листом

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int!, $shop_id: Int!, $category_ids: [Int!], $price: Float!) {
                ProductUpdate(id: $id, shop_id: $shop_id, category_ids: $category_ids, price: $price) {
                    code
                }
            }
        ', [
            'id' => $product->id,
            'shop_id' => $shop->id,
            'category_ids' => [$parent->id],
            'price' => 100,
        ])->assertJsonPath(
            'errors.0.message',
            "Категория «{$parent->title}» содержит вложенные категории. Выберите конечную категорию (без подкатегорий)."
        );
    }

    public function test_product_update_rejects_attribute_not_in_any_category_family(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $shop = Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);

        // Категория БЕЗ attribute_family.
        $cat = Category::factory()->create(['parent_id' => null]);

        // Создадим атрибут не из системного семейства и НЕ привязанный к категории.
        $isolatedFamily = \App\Models\AttributeFamily::create([
            'title' => 'Isolated ' . uniqid(),
            'is_system' => false,
        ]);
        $orphanAttr = \App\Models\Attribute::create([
            'title' => 'Orphan ' . uniqid(),
            'code' => 'orphan_' . uniqid(),
            'type_id' => \App\Models\Type::ATTRIBUTE_STRING,
        ]);
        // Привязываем атрибут к изолированному семейству (не к семейству категории)
        $isolatedFamily->attributes()->attach($orphanAttr->id, ['position' => 0, 'is_required' => false, 'is_configurable' => false]);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int!, $shop_id: Int!, $category_ids: [Int!], $price: Float!, $attrs: [AttributeInput]) {
                ProductUpdate(id: $id, shop_id: $shop_id, category_ids: $category_ids, price: $price, attributes: $attrs) {
                    code
                }
            }
        ', [
            'id' => $product->id,
            'shop_id' => $shop->id,
            'category_ids' => [$cat->id],
            'price' => 100,
            'attrs' => [['attribute_id' => $orphanAttr->id, 'string_value' => 'test']],
        ]);

        // Ожидаем ошибку валидации по полю attributes.0.attribute_id
        $response->assertGraphQLValidationError(
            'attributes.0.attribute_id',
            'Атрибут не принадлежит ни одной из выбранных категорий'
        );
    }

    public function test_product_update_without_category_ids_keeps_existing(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $shop = Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0, 'default' => false, 'slot_max_orders' => 10,
            'slot_time' => 30, 'payment_type_id' => 1, 'is_popular' => false,
        ]);
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
        $cat = Category::factory()->create(['parent_id' => null]);
        $product->categories()->attach($cat->id, ['position' => 0]);

        // Мутация без поля category_ids — связи должны сохраниться
        $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int!, $shop_id: Int!, $price: Float!) {
                ProductUpdate(id: $id, shop_id: $shop_id, price: $price) {
                    code
                }
            }
        ', [
            'id' => $product->id,
            'shop_id' => $shop->id,
            'price' => 100,
        ])->assertJsonPath('data.ProductUpdate.code', 200);

        $this->assertSame([$cat->id], $product->fresh('categories')->categories->pluck('id')->all());
    }
}
