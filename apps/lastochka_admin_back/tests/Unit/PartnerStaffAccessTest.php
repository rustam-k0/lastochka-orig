<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Support\PartnerStaffAccess;
use PHPUnit\Framework\TestCase;

/**
 * Чистая логика ограничения ролей партнёра — без БД (безопасно для общей БД).
 */
class PartnerStaffAccessTest extends TestCase
{
  public function test_allowed_roles_are_collector_and_courier(): void
  {
    $this->assertSame([Role::COLLECTOR, Role::COURIER], PartnerStaffAccess::ALLOWED_ROLES);
  }

  public function test_role_allowed_accepts_collector_and_courier(): void
  {
    $this->assertTrue(PartnerStaffAccess::roleAllowed(Role::COLLECTOR));
    $this->assertTrue(PartnerStaffAccess::roleAllowed(Role::COURIER));
  }

  public function test_role_allowed_rejects_admin_shop_superadmin_api(): void
  {
    $this->assertFalse(PartnerStaffAccess::roleAllowed(Role::ADMIN));
    $this->assertFalse(PartnerStaffAccess::roleAllowed(Role::SHOP));
    $this->assertFalse(PartnerStaffAccess::roleAllowed(Role::SUPERADMIN));
    $this->assertFalse(PartnerStaffAccess::roleAllowed(Role::API));
  }

  public function test_role_allowed_rejects_null_and_unknown(): void
  {
    $this->assertFalse(PartnerStaffAccess::roleAllowed(null));
    $this->assertFalse(PartnerStaffAccess::roleAllowed('something-else'));
  }
}
