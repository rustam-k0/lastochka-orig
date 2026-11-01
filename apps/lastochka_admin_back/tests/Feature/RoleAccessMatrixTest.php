<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleAccessMatrixTest extends TestCase
{
    use DatabaseTransactions;
    use \Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

    private function setFlag(string $value): void
    {
        AdminSetting::setValue('role_access_matrix_enabled', $value, 'boolean');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_is_global_scope_falls_back_to_hasRole_admin_when_flag_off(): void
    {
        $this->setFlag('0');
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));

        $this->assertTrue($admin->isGlobalScope());

        $shopUser = User::factory()->create();
        $shopUser->assignRole(Role::findOrCreate(Role::SHOP));
        $this->assertFalse($shopUser->fresh()->isGlobalScope());
    }

    public function test_is_global_scope_uses_scope_type_when_flag_on(): void
    {
        $this->setFlag('1');
        $manager = Role::findOrCreate('role_manager_test');
        $manager->scope_type = Role::SCOPE_GLOBAL;
        $manager->save();

        $user = User::factory()->create();
        $user->assignRole($manager);

        $this->assertTrue($user->fresh()->isGlobalScope());
        $this->assertFalse($user->fresh()->isShopScope());
    }

    public function test_section_directive_blocks_role_without_permission_when_flag_on(): void
    {
        $this->setFlag('1');

        // менеджер с доступом только к orders
        $manager = Role::findOrCreate('role_manager_block_test');
        $manager->scope_type = Role::SCOPE_SHOP; // не global, чтобы не байпасил
        $manager->save();
        $manager->givePermissionTo(\App\Models\Permission::findOrCreate('section.orders'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($manager);

        // запрос staffs (section.staffs нет) → ошибка авторизации
        $response = $this->actingAs($user)->graphQL('{ users_paginate(first: 1) { data { id } } }');
        $response->assertGraphQLErrorMessage('Нет доступа к разделу: staffs');
    }

    public function test_create_role_with_sections_and_sync(): void
    {
        $this->setFlag('1');
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));

        $resp = $this->actingAs($admin)->graphQL('
            mutation {
              createRole(name_ru: "Менеджер", scope_type: "global", section_keys: ["orders","products"]) {
                id name scope_type is_system section_keys
              }
            }');
        $resp->assertJsonPath('data.createRole.is_system', false);
        $resp->assertJsonPath('data.createRole.scope_type', 'global');
        $this->assertEqualsCanonicalizing(['orders','products'], $resp->json('data.createRole.section_keys'));

        $roleId = $resp->json('data.createRole.id');
        $sync = $this->actingAs($admin)->graphQL('
            mutation { syncRoleSections(role_id: ' . $roleId . ', section_keys: ["clients"]) { section_keys } }');
        $this->assertEqualsCanonicalizing(['clients'], $sync->json('data.syncRoleSections.section_keys'));
    }

    public function test_cannot_delete_system_role(): void
    {
        $this->setFlag('1');
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));
        $adminRoleId = Role::where('name', Role::ADMIN)->value('id');

        $resp = $this->actingAs($admin)->graphQL('mutation { deleteRole(id: ' . $adminRoleId . ') { code } }');
        $resp->assertGraphQLErrorMessage('Системную роль удалить нельзя.');
    }

    public function test_user_update_changes_role(): void
    {
        $this->setFlag('1');
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));

        $target = User::factory()->create();
        $target->assignRole(Role::findOrCreate(Role::SHOP));
        $newRole = Role::findOrCreate('role_update_test');

        $this->actingAs($admin)->graphQL('
            mutation { UserUpdate(id: ' . $target->id . ', role_id: ' . $newRole->id . ',
                name: "Тест", surname: "Тестов", phone: "+79990001122") { code } }')
            ->assertJsonPath('data.UserUpdate.code', 200);

        $this->assertTrue($target->fresh()->hasRole('role_update_test'));
    }

    /**
     * Кастомная роль без гранта «Роли и доступы» к матрице не допускается,
     * даже если её scope глобальный (MID-210).
     */
    public function test_custom_global_role_without_roles_section_cannot_manage_roles(): void
    {
        $this->setFlag('1');

        $role = Role::findOrCreate('role_no_roles_section_test');
        $role->scope_type = Role::SCOPE_GLOBAL;
        $role->save();
        $role->givePermissionTo(Permission::findOrCreate('section.orders'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user->fresh())
            ->graphQL('mutation {
                createRole(name_ru: "Не должно создаться", scope_type: "global", section_keys: ["orders"]) { id }
            }')
            ->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    /**
     * Грант section.roles открывает созданной роли раздел «Роли и доступы»:
     * она видит список ролей и правит матрицу (MID-210).
     */
    public function test_custom_role_with_roles_section_can_manage_roles(): void
    {
        $this->setFlag('1');

        $role = Role::findOrCreate('role_with_roles_section_test');
        $role->scope_type = Role::SCOPE_GLOBAL;
        $role->save();
        $role->givePermissionTo(Permission::findOrCreate('section.roles'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);

        $created = $this->actingAs($user->fresh())
            ->graphQL('mutation {
                createRole(name_ru: "Созданная менеджером", scope_type: "shop", section_keys: ["orders"]) {
                    id section_keys
                }
            }');
        $created->assertJsonPath('data.createRole.section_keys', ['orders']);

        $this->actingAs($user->fresh())
            ->graphQL('mutation {
                syncRoleSections(role_id: ' . $created->json('data.createRole.id') . ', section_keys: ["clients"]) {
                    section_keys
                }
            }')
            ->assertJsonPath('data.syncRoleSections.section_keys', ['clients']);
    }

    /**
     * При выключенной матрице гранты не участвуют в enforcement — доступ к
     * управлению ролями остаётся прежним (только admin / superadmin).
     */
    public function test_roles_section_grant_is_ignored_when_flag_off(): void
    {
        $this->setFlag('0');

        $role = Role::findOrCreate('role_flag_off_test');
        $role->scope_type = Role::SCOPE_GLOBAL;
        $role->save();
        $role->givePermissionTo(Permission::findOrCreate('section.roles'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user->fresh())
            ->graphQL('mutation {
                createRole(name_ru: "Флаг выключен", scope_type: "global", section_keys: ["orders"]) { id }
            }')
            ->assertGraphQLErrorMessage('This action is unauthorized.');

        $this->setFlag('1');
    }

    /**
     * Самострел: снять «Роли и доступы» со своей же роли нельзя — вернуть
     * галочку было бы некому (MID-210).
     */
    public function test_cannot_revoke_roles_section_from_own_role(): void
    {
        $this->setFlag('1');

        $role = Role::findOrCreate('role_self_lockout_test');
        $role->scope_type = Role::SCOPE_GLOBAL;
        $role->save();
        $role->givePermissionTo(Permission::findOrCreate('section.roles'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user->fresh())
            ->graphQL('mutation {
                syncRoleSections(role_id: ' . $role->id . ', section_keys: ["orders"]) { section_keys }
            }')
            ->assertGraphQLErrorMessage(
                'Нельзя снять раздел «Роли и доступы» со своей роли — доступ вернуть будет некому.'
            );

        $this->assertTrue($role->fresh()->permissions->contains('name', 'section.roles'));

        // Другие разделы своей роли при этом правятся свободно.
        $kept = $this->actingAs($user->fresh())
            ->graphQL('mutation {
                syncRoleSections(role_id: ' . $role->id . ', section_keys: ["roles","clients"]) { section_keys }
            }');
        $this->assertEqualsCanonicalizing(
            ['roles', 'clients'],
            $kept->json('data.syncRoleSections.section_keys')
        );
    }

    /**
     * Shop-роль раздатчиком доступов не становится даже с грантом: матрица
     * оперирует ролями всех магазинов сразу (MID-210).
     */
    public function test_shop_scope_role_with_roles_section_cannot_manage_roles(): void
    {
        $this->setFlag('1');

        $role = Role::findOrCreate('role_shop_scope_roles_test');
        $role->scope_type = Role::SCOPE_SHOP;
        $role->save();
        $role->givePermissionTo(Permission::findOrCreate('section.roles'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertFalse(app(\App\Policies\AdminPolicy::class)->manageRoles($user->fresh()));
    }

    /** Супер-админ и админ управляют ролями без всяких грантов. */
    public function test_admin_and_superadmin_always_manage_roles(): void
    {
        $this->setFlag('1');

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));
        $this->assertTrue(app(\App\Policies\AdminPolicy::class)->manageRoles($admin->fresh()));

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate(Role::SUPERADMIN));
        $this->assertTrue(app(\App\Policies\AdminPolicy::class)->manageRoles($superAdmin->fresh()));
    }
}
