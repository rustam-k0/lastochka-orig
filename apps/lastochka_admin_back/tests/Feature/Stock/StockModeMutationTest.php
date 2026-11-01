<?php

namespace Tests\Feature\Stock;

use App\Models\AdminSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * GraphQL-мутация SetCategoryStockMode (LASTOCHKI-JAMI-11207):
 * установка режима остатков (normal/ignore/fixed) на витринной категории,
 * аудит изменения в stock_mode_changes, пересчёт effective-полей товаров.
 *
 * Мутация защищена @guard @can(model: "Admin", ability: "onlyAdmin")
 * @section(key: "categories") — как все управляющие Category-мутации
 * (CategoryHide/CategoryPublish/SetCategoryQuantum). Тест авторизуется
 * глобальным админом (роль Role::ADMIN → isGlobalScope() == true).
 */
class StockModeMutationTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    /**
     * Создаёт глобального админа и логинит его — проходит @can(onlyAdmin)
     * (AdminPolicy::onlyAdmin == User::isGlobalScope(), которое при выключенной
     * матрице доступов требует hasRole(Role::ADMIN)) и @section(categories)
     * (admin — суперпользователь). Паттерн из CategoryQuantumTest.
     */
    private function actingAsGlobalAdmin(): User
    {
        AdminSetting::setValue('role_access_matrix_enabled', '0', 'boolean');
        $admin = User::create([
            'name' => 'Admin',
            'phone' => '+7901' . mt_rand(1000000, 9999999),
            'email' => uniqid('admin_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));
        $this->assertTrue($admin->isGlobalScope());
        $this->actingAs($admin);

        return $admin;
    }

    public function test_set_category_ignore_recalcs_products(): void
    {
        $this->actingAsGlobalAdmin();
        $shop = Shop::firstOrCreate(['id' => 990208], ['title' => 'M', 'code' => 'm208', 'ignore_stock' => false]);
        $cat = Category::forceCreate(['title' => 'Салаты', 'link' => 'salaty-' . uniqid(), 'stock_mode' => 'normal']);
        $product = Product::forceCreate(['title' => 'Цезарь', 'shop_id' => $shop->id, 'base_unit_type_id' => Type::INFO_WEIGHT_PIECE]);
        $product->categories()->attach($cat->id);

        $this->graphQL('
            mutation($id: Int!) {
                SetCategoryStockMode(category_id: $id, stock_mode: "ignore") { id stock_mode }
            }
        ', ['id' => $cat->id])->assertJsonPath('data.SetCategoryStockMode.stock_mode', 'ignore');

        $this->assertSame('ignore', $product->fresh()->effective_stock_mode);
        $this->assertDatabaseHas('stock_mode_changes', ['entity_type' => 'category', 'entity_id' => $cat->id, 'new_mode' => 'ignore']);
    }

    public function test_set_category_fixed_with_unit(): void
    {
        $this->actingAsGlobalAdmin();
        $cat = Category::forceCreate(['title' => 'Выпечка', 'link' => 'vypechka-' . uniqid(), 'stock_mode' => 'normal']);
        $this->graphQL('
            mutation($id: Int!) {
                SetCategoryStockMode(category_id: $id, stock_mode: "fixed", fixed_stock_quantity: 100, fixed_stock_unit_type_id: 1) { id stock_mode fixed_stock_quantity }
            }
        ', ['id' => $cat->id])->assertJsonPath('data.SetCategoryStockMode.fixed_stock_quantity', 100);
        $this->assertSame('fixed', $cat->fresh()->stock_mode);
    }

    public function test_set_category_fixed_rejects_bad_unit(): void
    {
        $this->actingAsGlobalAdmin();
        $cat = Category::forceCreate(['title' => 'X', 'link' => 'x-' . uniqid(), 'stock_mode' => 'normal']);
        $this->graphQL('
            mutation($id: Int!) {
                SetCategoryStockMode(category_id: $id, stock_mode: "fixed", fixed_stock_quantity: 5, fixed_stock_unit_type_id: 4) { id }
            }
        ', ['id' => $cat->id])->assertJsonPath('errors.0.extensions.validation.fixed_stock_unit_type_id.0', 'Единица должна быть: грамм или штука.');
    }

    public function test_bulk_categories_set_ignore(): void
    {
        $this->actingAsGlobalAdmin();
        $c1 = Category::forceCreate(['title' => 'A', 'link' => 'bulk-a-' . uniqid(), 'stock_mode' => 'normal']);
        $c2 = Category::forceCreate(['title' => 'B', 'link' => 'bulk-b-' . uniqid(), 'stock_mode' => 'normal']);
        $this->graphQL('
            mutation($ids: [Int!]!) { SetCategoriesStockMode(ids: $ids, stock_mode: "ignore") }
        ', ['ids' => [$c1->id, $c2->id]])->assertJsonPath('data.SetCategoriesStockMode', 2);
        $this->assertSame('ignore', $c1->fresh()->stock_mode);
        $this->assertSame('ignore', $c2->fresh()->stock_mode);
    }

    /**
     * ProductUpdate с stock_mode=ignore материализует effective_stock_mode и
     * пишет аудит. Резолвер Product\ProductUpdate возвращает DefaultResponse
     * (не Product), поэтому effective проверяется через БД ($product->fresh()),
     * а не через GraphQL-selection. shop_id + price передаём — резолвер
     * (updatePrices / CategoryQuantumService) обращается к ним без `??`.
     */
    public function test_product_update_sets_ignore(): void
    {
        $this->actingAsGlobalAdmin();
        $shop = Shop::firstOrCreate(['id' => 990209], ['title' => 'M', 'code' => 'm209', 'ignore_stock' => false]);
        $product = Product::forceCreate([
            'title' => 'P',
            'shop_id' => $shop->id,
            'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        $this->graphQL('
            mutation($id: Int!, $shop_id: Int!) {
                ProductUpdate(id: $id, shop_id: $shop_id, title: "P", price: 10, stock_mode: "ignore") { code message }
            }
        ', ['id' => $product->id, 'shop_id' => $shop->id])
            ->assertJsonPath('data.ProductUpdate.code', 200);

        $fresh = $product->fresh();
        $this->assertSame('ignore', $fresh->stock_mode);
        $this->assertSame('ignore', $fresh->effective_stock_mode);

        $this->assertDatabaseHas('stock_mode_changes', [
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'new_mode' => 'ignore',
        ]);
    }

    /**
     * Единица фиксированного лимита товара — из семейства товара: «5 штук» у
     * весового становились 5 г (effective_fixed_stock в граммах), товар
     * фактически пропадал из наличия. На уровне категории это уже проверялось.
     */
    public function test_product_update_rejects_fixed_unit_of_other_family(): void
    {
        $this->actingAsGlobalAdmin();
        $shop = Shop::firstOrCreate(['id' => 990210], ['title' => 'M', 'code' => 'm210', 'ignore_stock' => false]);
        $product = Product::forceCreate([
            'title' => 'Филе',
            'shop_id' => $shop->id,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'info_weight' => 300,
        ]);

        $this->graphQL('
            mutation($id: Int!, $shop_id: Int!) {
                ProductUpdate(id: $id, shop_id: $shop_id, title: "Филе", price: 10, stock_mode: "fixed",
                    fixed_stock_quantity: 5, fixed_stock_unit_type_id: 1) { code message }
            }
        ', ['id' => $product->id, 'shop_id' => $shop->id])
            ->assertJsonPath('errors.0.extensions.validation.fixed_stock_unit_type_id.0', 'Единица лимита не подходит товару: для весового — граммы.');

        $this->assertNull($product->fresh()->stock_mode);
    }

    public function test_product_update_accepts_fixed_unit_of_same_family(): void
    {
        $this->actingAsGlobalAdmin();
        $shop = Shop::firstOrCreate(['id' => 990211], ['title' => 'M', 'code' => 'm211', 'ignore_stock' => false]);
        $product = Product::forceCreate([
            'title' => 'Филе',
            'shop_id' => $shop->id,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'info_weight' => 300,
        ]);

        $this->graphQL('
            mutation($id: Int!, $shop_id: Int!) {
                ProductUpdate(id: $id, shop_id: $shop_id, title: "Филе", price: 10, stock_mode: "fixed",
                    fixed_stock_quantity: 5000, fixed_stock_unit_type_id: 2) { code message }
            }
        ', ['id' => $product->id, 'shop_id' => $shop->id])
            ->assertJsonPath('data.ProductUpdate.code', 200);

        $this->assertSame(5000, (int) $product->fresh()->effective_fixed_stock);
    }

    public function test_bulk_fixed_mode_rejects_mixed_families_without_changes(): void
    {
        $this->actingAsGlobalAdmin();
        $shop = Shop::firstOrCreate(['id' => 990212], ['title' => 'M', 'code' => 'm212', 'ignore_stock' => false]);
        $weighted = Product::forceCreate([
            'title' => 'Филе', 'shop_id' => $shop->id,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM, 'info_weight_type_id' => Type::INFO_WEIGHT_GRAM, 'info_weight' => 300,
        ]);
        $piece = Product::forceCreate([
            'title' => 'Сыр', 'shop_id' => $shop->id,
            'base_unit_type_id' => Type::INFO_WEIGHT_PIECE, 'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        $this->graphQL('
            mutation($ids: [Int!]!) {
                SetProductsStockMode(ids: $ids, stock_mode: "fixed", fixed_stock_quantity: 500, fixed_stock_unit_type_id: 2)
            }
        ', ['ids' => [$weighted->id, $piece->id]])
            ->assertJsonPath('errors.0.extensions.validation.fixed_stock_unit_type_id.0', 'Единица лимита не подходит товарам: Сыр.');

        $this->assertNull($weighted->fresh()->stock_mode);
        $this->assertNull($piece->fresh()->stock_mode);
    }

    /**
     * При создании подкатегории через CategoryCreate с родителем, у которого
     * stock_mode != normal, новая подкатегория наследует stock_mode /
     * fixed_stock_quantity / fixed_stock_unit_type_id родителя
     * (LASTOCHKI-JAMI-11207). CategoryCreate возвращает DefaultResponse —
     * поэтому унаследованные поля проверяются через БД.
     */
    public function test_subcategory_inherits_parent_stock_mode(): void
    {
        $this->actingAsGlobalAdmin();
        $parent = Category::forceCreate([
            'title' => 'P',
            'link' => 'inherit-parent-' . uniqid(),
            'stock_mode' => 'fixed',
            'fixed_stock_quantity' => 7,
            'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        $this->graphQL('
            mutation($pid: Int!) {
                CategoryCreate(title: "Child", parent: $pid) { code message }
            }
        ', ['pid' => $parent->id])->assertJsonPath('data.CategoryCreate.code', 200);

        $child = Category::where('title', 'Child')->latest('id')->first();
        $this->assertNotNull($child);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame('fixed', $child->stock_mode);
        $this->assertSame(7, (int) $child->fixed_stock_quantity);
        $this->assertSame(Type::INFO_WEIGHT_PIECE, (int) $child->fixed_stock_unit_type_id);
    }
}
