<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\OrderAction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Свежая установка (migrate, затем db:seed) должна дать системные роли в том же виде,
 * что на стендах с историей: миграции 2026_05_26 и позже ставят флаги и права только
 * существующим ролям, а раньше роли создавались сидерами уже после всех миграций.
 */
class SystemRolesBootstrapTest extends TestCase
{
  use DatabaseTransactions;

  /** @test */
  public function system_roles_are_flagged_as_on_existing_stands(): void
  {
    $expected = [
      Role::ADMIN => Role::SCOPE_GLOBAL,
      Role::SHOP => Role::SCOPE_SHOP,
      Role::API => Role::SCOPE_GLOBAL,
      Role::COLLECTOR => Role::SCOPE_GLOBAL,
      Role::COURIER => Role::SCOPE_GLOBAL,
      Role::SUPERADMIN => Role::SCOPE_GLOBAL,
    ];

    foreach ($expected as $name => $scope) {
      $role = Role::where('name', $name)->first();

      $this->assertNotNull($role, "Нет системной роли {$name}");
      $this->assertTrue((bool) $role->is_system, "Роль {$name} не помечена системной");
      $this->assertSame($scope, $role->scope_type, "У роли {$name} неверный scope_type");
    }
  }

  /** @test */
  public function admin_and_shop_roles_receive_permissions_from_migrations(): void
  {
    $admin = Role::where('name', Role::ADMIN)->firstOrFail();
    $shop = Role::where('name', Role::SHOP)->firstOrFail();

    $this->assertTrue($admin->hasPermissionTo('section.orders'));
    $this->assertTrue($admin->hasPermissionTo('section.pages'));
    $this->assertTrue($admin->hasPermissionTo(OrderAction::MARK_PAID));
    $this->assertTrue($shop->hasPermissionTo('section.my_store'));
  }

  /** @test */
  public function seeded_admin_also_gets_superadmin(): void
  {
    $this->assertTrue($this->adminUser()->hasRole(Role::SUPERADMIN));
  }
}
