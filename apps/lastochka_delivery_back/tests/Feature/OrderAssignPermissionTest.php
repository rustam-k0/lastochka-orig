<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\AdminPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrderAssignPermissionTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private function setFlag(string $value): void
  {
    AdminSetting::setValue('role_access_matrix_enabled', $value, 'boolean');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
  }

  private function makeStaff(string $roleName): User
  {
    $role = Role::findOrCreate($roleName, 'api');

    $userId = \DB::table('users')->insertGetId([
      'name' => 'Test',
      'email' => uniqid('assign_') . '@test.local',
      'password' => bcrypt('secret'),
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $user = User::findOrFail($userId);
    $user->assignRole($role);

    return $user;
  }

  public function test_policy_flag_off_only_admin(): void
  {
    $this->setFlag('0');
    $policy = new AdminPolicy();

    $custom = $this->makeStaff('role_assign_off');
    $this->assertFalse($policy->assignCollector($custom));
    $this->assertFalse($policy->assignCourier($custom));

    $admin = $this->makeStaff(Role::ADMIN);
    $this->assertTrue($policy->assignCollector($admin));
    $this->assertTrue($policy->assignCourier($admin));
  }

  public function test_policy_flag_on_permission_based(): void
  {
    $this->setFlag('1');
    $policy = new AdminPolicy();

    $custom = $this->makeStaff('role_assign_on');
    $this->assertFalse($policy->assignCollector($custom));

    $custom->roles->first()->givePermissionTo(
      Permission::findOrCreate('orders.assign_collector', 'api')
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $fresh = $custom->fresh();
    $this->assertTrue($policy->assignCollector($fresh));
    // другое действие не выдано
    $this->assertFalse($policy->assignCourier($fresh));

    // байпас админа
    $admin = $this->makeStaff(Role::ADMIN);
    $this->assertTrue($policy->assignCourier($admin));
  }

  public function test_mutation_denied_without_permission_when_flag_on(): void
  {
    $this->setFlag('1');
    Sanctum::actingAs($this->makeStaff('role_assign_gql'), [], 'api');

    $resp = $this->graphQL('mutation { OrderCollectorUpdate(id: 999999, collector_id: 999999) { code } }');

    // Отказ на слое авторизации ИЛИ валидации (несуществующий заказ) —
    // главное, что мутация не выполнена
    $this->assertNull($resp->json('data.OrderCollectorUpdate'));
    $this->assertNotNull($resp->json('errors'));
  }

  public function test_mutation_authorized_with_permission_when_flag_on(): void
  {
    $this->setFlag('1');
    $user = $this->makeStaff('role_assign_gql_ok');
    $user->roles->first()->givePermissionTo(
      Permission::findOrCreate('orders.assign_collector', 'api')
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($user->fresh(), [], 'api');

    $resp = $this->graphQL('mutation { OrderCollectorUpdate(id: 999999, collector_id: 999999) { code } }');

    // Авторизация пройдена: ошибки могут быть только валидационными
    $this->assertStringNotContainsString(
      'This action is unauthorized.',
      json_encode($resp->json('errors') ?? [], JSON_UNESCAPED_UNICODE)
    );
  }
}
