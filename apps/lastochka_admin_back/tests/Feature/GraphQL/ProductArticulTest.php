<?php

declare(strict_types=1);

namespace Tests\Feature\GraphQL;

use App\Models\AdminSetting;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductArticulTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    /**
     * Создаёт глобального админа и логинит его (паттерн из StockModeMutationTest) —
     * тестовая БД foodlink_custom_test пустая, $this->adminUser() падает.
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

    private function makeShop(): Shop
    {
        return Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    public function test_product_create_saves_manual_articul(): void
    {
        $this->actingAsGlobalAdmin();

        $shop = $this->makeShop();

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation ($shop_id: Int!, $price: Float!, $articul: String, $info_weight_type_id: Int!) {
                ProductCreate(shop_id: $shop_id, price: $price, articul: $articul, title: "Тестовый товар", info_weight_type_id: $info_weight_type_id) {
                    code
                }
            }
        ', [
            'shop_id' => $shop->id,
            'price' => 120,
            'articul' => '1231231',
            'info_weight_type_id' => 1,
        ]);

        $response->assertJsonPath('data.ProductCreate.code', 200);

        $this->assertDatabaseHas('products', [
            'shop_id' => $shop->id,
            'articul' => '1231231',
        ]);
    }

    public function test_product_update_sets_articul_on_existing_product(): void
    {
        $this->actingAsGlobalAdmin();

        $shop = $this->makeShop();
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int!, $shop_id: Int!, $price: Float!, $articul: String) {
                ProductUpdate(id: $id, shop_id: $shop_id, price: $price, articul: $articul) {
                    code
                }
            }
        ', [
            'id' => $product->id,
            'shop_id' => $shop->id,
            'price' => 100,
            'articul' => 'SKU-42',
        ])->assertJsonPath('data.ProductUpdate.code', 200);

        $this->assertSame('SKU-42', $product->fresh()->articul);
    }

    public function test_product_create_rejects_duplicate_articul_in_same_shop(): void
    {
        $this->actingAsGlobalAdmin();

        $shop = $this->makeShop();

        Product::create([
            'title' => 'TP existing ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
            'articul' => 'DUP-1',
        ]);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation ($shop_id: Int!, $price: Float!, $articul: String, $info_weight_type_id: Int!) {
                ProductCreate(shop_id: $shop_id, price: $price, articul: $articul, title: "Новый товар", info_weight_type_id: $info_weight_type_id) {
                    code
                }
            }
        ', [
            'shop_id' => $shop->id,
            'price' => 50,
            'articul' => 'DUP-1',
            'info_weight_type_id' => 1,
        ]);

        $response->assertGraphQLValidationError(
            'articul',
            'Товар с таким артикулом уже существует в этом магазине.'
        );
    }

    public function test_articul_can_repeat_across_different_shops(): void
    {
        $this->actingAsGlobalAdmin();

        $shopA = $this->makeShop();
        $shopB = $this->makeShop();

        Product::create([
            'title' => 'TP shopA ' . uniqid(),
            'shop_id' => $shopA->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
            'articul' => 'SAME-SKU',
        ]);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation ($shop_id: Int!, $price: Float!, $articul: String, $info_weight_type_id: Int!) {
                ProductCreate(shop_id: $shop_id, price: $price, articul: $articul, title: "Товар в другом магазине", info_weight_type_id: $info_weight_type_id) {
                    code
                }
            }
        ', [
            'shop_id' => $shopB->id,
            'price' => 50,
            'articul' => 'SAME-SKU',
            'info_weight_type_id' => 1,
        ]);

        $response->assertJsonPath('data.ProductCreate.code', 200);

        $this->assertDatabaseHas('products', [
            'shop_id' => $shopB->id,
            'articul' => 'SAME-SKU',
        ]);
    }

    public function test_product_update_keeping_own_articul_does_not_trigger_uniqueness_error(): void
    {
        $this->actingAsGlobalAdmin();

        $shop = $this->makeShop();
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
            'articul' => 'KEEP-ME',
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int!, $shop_id: Int!, $price: Float!, $articul: String) {
                ProductUpdate(id: $id, shop_id: $shop_id, price: $price, articul: $articul) {
                    code
                }
            }
        ', [
            'id' => $product->id,
            'shop_id' => $shop->id,
            'price' => 200,
            'articul' => 'KEEP-ME',
        ])->assertJsonPath('data.ProductUpdate.code', 200);
    }

    public function test_multiple_products_without_articul_do_not_violate_unique_index(): void
    {
        $this->actingAsGlobalAdmin();

        $shop = $this->makeShop();

        $first = $this->graphQL(/** @lang GraphQL */ '
            mutation ($shop_id: Int!, $price: Float!, $info_weight_type_id: Int!) {
                ProductCreate(shop_id: $shop_id, price: $price, title: "Без артикула 1", info_weight_type_id: $info_weight_type_id) {
                    code
                }
            }
        ', [
            'shop_id' => $shop->id,
            'price' => 50,
            'info_weight_type_id' => 1,
        ]);
        $first->assertJsonPath('data.ProductCreate.code', 200);

        $second = $this->graphQL(/** @lang GraphQL */ '
            mutation ($shop_id: Int!, $price: Float!, $articul: String, $info_weight_type_id: Int!) {
                ProductCreate(shop_id: $shop_id, price: $price, articul: $articul, title: "Без артикула 2", info_weight_type_id: $info_weight_type_id) {
                    code
                }
            }
        ', [
            'shop_id' => $shop->id,
            'price' => 50,
            'articul' => '',
            'info_weight_type_id' => 1,
        ]);
        $second->assertJsonPath('data.ProductCreate.code', 200);
    }
}
