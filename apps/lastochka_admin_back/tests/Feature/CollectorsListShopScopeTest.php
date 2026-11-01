<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * MID-107: списки collectors/couriers должны быть доступны shop-scope ролям
 * (гейт onlyShop + scope accessible), а не только глобальным — иначе роль
 * с включённым свитчем «Назначение сборщика» видит пустой список в модалке.
 */
class CollectorsListShopScopeTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private Shop $ownShop;
    private Shop $otherShop;
    private User $shopUser;
    private User $ownCollector;
    private User $otherCollector;
    private User $ownCourier;

    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::setValue('role_access_matrix_enabled', '1', 'boolean');

        $this->ownShop = Shop::create(['title' => 'Collectors own shop ' . uniqid()]);
        $this->otherShop = Shop::create(['title' => 'Collectors other shop ' . uniqid()]);

        $role = Role::findOrCreate('role_collectors_list_test');
        $role->scope_type = Role::SCOPE_SHOP;
        $role->save();
        $role->givePermissionTo(Permission::findOrCreate('section.orders'));
        $role->givePermissionTo(Permission::findOrCreate('orders.assign_collector'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->shopUser = User::factory()->create();
        $this->shopUser->assignRole($role);
        UserShop::create(['shop_id' => $this->ownShop->id, 'user_id' => $this->shopUser->id]);

        $this->ownCollector = $this->makeStaff(Role::COLLECTOR, $this->ownShop);
        $this->otherCollector = $this->makeStaff(Role::COLLECTOR, $this->otherShop);
        $this->ownCourier = $this->makeStaff(Role::COURIER, $this->ownShop);
    }

    private function makeStaff(string $roleName, Shop $shop): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate($roleName));
        UserShop::create(['shop_id' => $shop->id, 'user_id' => $user->id]);

        return $user;
    }

    public function test_shop_scope_role_sees_collectors_of_own_shop(): void
    {
        $response = $this->actingAs($this->shopUser)->graphQL(
            '{ collectors(shop_id: ' . $this->ownShop->id . ') { id } }'
        );

        $response->assertJson(fn ($json) => $json->missing('errors')->etc());
        $ids = array_column($response->json('data.collectors'), 'id');
        $this->assertContains($this->ownCollector->id, $ids);
        $this->assertNotContains($this->otherCollector->id, $ids);
        $this->assertNotContains($this->ownCourier->id, $ids);
    }

    public function test_shop_scope_role_does_not_see_foreign_shop_collectors_without_shop_id(): void
    {
        $response = $this->actingAs($this->shopUser)->graphQL('{ collectors { id } }');

        $response->assertJson(fn ($json) => $json->missing('errors')->etc());
        $ids = array_column($response->json('data.collectors'), 'id');
        $this->assertContains($this->ownCollector->id, $ids);
        $this->assertNotContains($this->otherCollector->id, $ids);
    }

    public function test_shop_scope_role_sees_couriers_of_own_shop(): void
    {
        $response = $this->actingAs($this->shopUser)->graphQL(
            '{ couriers(shop_id: ' . $this->ownShop->id . ') { id } }'
        );

        $response->assertJson(fn ($json) => $json->missing('errors')->etc());
        $ids = array_column($response->json('data.couriers'), 'id');
        $this->assertContains($this->ownCourier->id, $ids);
        $this->assertNotContains($this->ownCollector->id, $ids);
    }

    public function test_global_admin_sees_collectors_of_any_shop(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));

        $response = $this->actingAs($admin)->graphQL(
            '{ collectors(shop_id: ' . $this->otherShop->id . ') { id } }'
        );

        $response->assertJson(fn ($json) => $json->missing('errors')->etc());
        $ids = array_column($response->json('data.collectors'), 'id');
        $this->assertContains($this->otherCollector->id, $ids);
    }

    public function test_client_scope_user_still_denied(): void
    {
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->graphQL('{ collectors { id } }');

        $response->assertGraphQLErrorMessage('This action is unauthorized.');
    }
}
