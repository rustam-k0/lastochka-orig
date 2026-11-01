<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\OrderAction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrderActionMatrixTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    protected function setUp(): void
    {
        parent::setUp();
        AdminSetting::setValue('role_access_matrix_enabled', '1', 'boolean');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));

        return $admin;
    }

    public function test_sync_role_order_actions_grants_and_revokes(): void
    {
        $admin = $this->makeAdmin();
        $role = Role::findOrCreate('role_tovaroved_test');

        $resp = $this->actingAs($admin)->graphQL('
            mutation { syncRoleOrderActions(role_id: ' . $role->id . ', action_keys: ["orders.change_status"]) { order_action_keys } }');
        $this->assertEqualsCanonicalizing(
            [OrderAction::CHANGE_STATUS],
            $resp->json('data.syncRoleOrderActions.order_action_keys')
        );

        $resp2 = $this->actingAs($admin)->graphQL('
            mutation { syncRoleOrderActions(role_id: ' . $role->id . ', action_keys: ["orders.cancel","orders.export"]) { order_action_keys } }');
        $this->assertEqualsCanonicalizing(
            [OrderAction::CANCEL, OrderAction::EXPORT],
            $resp2->json('data.syncRoleOrderActions.order_action_keys')
        );
    }

    public function test_sync_rejects_unknown_action_key(): void
    {
        $admin = $this->makeAdmin();
        $role = Role::findOrCreate('role_unknown_key_test');

        $resp = $this->actingAs($admin)->graphQL('
            mutation { syncRoleOrderActions(role_id: ' . $role->id . ', action_keys: ["orders.hack"]) { id } }');
        $this->assertStringContainsString(
            'Неизвестное действие',
            json_encode($resp->json('errors'), JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_sync_forbidden_for_admin_role(): void
    {
        $admin = $this->makeAdmin();
        $adminRoleId = Role::where('name', Role::ADMIN)->value('id');

        $resp = $this->actingAs($admin)->graphQL('
            mutation { syncRoleOrderActions(role_id: ' . $adminRoleId . ', action_keys: []) { id } }');
        $resp->assertGraphQLErrorMessage('Нельзя менять доступы администратора.');
    }

    public function test_sync_forbidden_for_non_manager(): void
    {
        $role = Role::findOrCreate('role_not_manager_test');
        $user = User::factory()->create();
        $user->assignRole($role);

        $resp = $this->actingAs($user)->graphQL('
            mutation { syncRoleOrderActions(role_id: ' . $role->id . ', action_keys: []) { id } }');
        $resp->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    public function test_user_order_action_keys_and_admin_bypass(): void
    {
        $role = Role::findOrCreate('role_keys_test');
        $role->givePermissionTo(Permission::findOrCreate(OrderAction::CANCEL));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);

        $resp = $this->actingAs($user)->graphQL('{ me { order_action_keys } }');
        $this->assertEqualsCanonicalizing([OrderAction::CANCEL], $resp->json('data.me.order_action_keys'));

        $respAdmin = $this->actingAs($this->makeAdmin())->graphQL('{ me { order_action_keys } }');
        $this->assertEqualsCanonicalizing(OrderAction::all(), $respAdmin->json('data.me.order_action_keys'));
    }

    public function test_roles_query_returns_order_action_keys(): void
    {
        $admin = $this->makeAdmin();
        $role = Role::findOrCreate('role_list_keys_test');
        $role->givePermissionTo(Permission::findOrCreate(OrderAction::EXPORT));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $resp = $this->actingAs($admin)->graphQL('{ roles { name order_action_keys } }');
        $row = collect($resp->json('data.roles'))->firstWhere('name', 'role_list_keys_test');
        $this->assertEqualsCanonicalizing([OrderAction::EXPORT], $row['order_action_keys']);

        $adminRow = collect($resp->json('data.roles'))->firstWhere('name', 'admin');
        $this->assertEqualsCanonicalizing(OrderAction::all(), $adminRow['order_action_keys']);
    }
}
