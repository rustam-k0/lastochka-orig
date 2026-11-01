<?php

namespace App\Policies;

use App\Models\AdminSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AdminPolicy
{
  use HandlesAuthorization;

  /** Право-раздел «Роли и доступы» (menu_items.name = roles). */
  public const SECTION_ROLES = 'section.roles';

  /**
   * Есть ли у пользователя права администратора
   */
  public function onlyAdmin(User $user): bool
  {
    return $user->isGlobalScope();
  }

  /**
   * Есть ли у пользователя права партнера
   */
  public function onlyShop(User $user): bool
  {
    if ($this->onlyAdmin($user)) {
      return true;
    }

    return $user->isShopScope();
  }

  /**
   * Может ли пользователь управлять разделом «Настройки».
   * При включённой матрице доступов — только супер-админ (роль выдаётся
   * исключительно через БД); иначе — прежнее поведение (админ).
   */
  public function manageSettings(User $user): bool
  {
    if (AdminSetting::getValue('role_access_matrix_enabled') === '1') {
      return $user->isSuperAdmin();
    }

    return $user->isGlobalScope();
  }

  /**
   * Может ли пользователь управлять ролями и матрицей доступов.
   *
   * admin и super-admin — всегда (защита от самоблокировки). Кастомной роли
   * доступ выдаётся точечно, галочкой раздела «Роли и доступы» в самой матрице
   * (грант section.roles) — и только при включённом флаге матрицы: без него
   * гранты не участвуют в enforcement, и поведение остаётся прежним.
   *
   * Дополнительно требуется глобальный scope: раздатчик доступов работает со
   * всеми ролями системы, включая чужие магазины, поэтому мерчант (shop-scope)
   * не должен получать его даже по ошибочно поставленной галочке.
   *
   * Право проверяется через getAllPermissions(), а не hasPermissionTo():
   * последний кидает PermissionDoesNotExistException, если миграция,
   * создающая section.roles, ещё не накатилась на окружении.
   */
  public function manageRoles(User $user): bool
  {
    if ($user->hasRole(Role::ADMIN) || $user->hasRole(Role::SUPERADMIN)) {
      return true;
    }

    if (AdminSetting::getValue('role_access_matrix_enabled') !== '1') {
      return false;
    }

    if (!$user->isGlobalScope()) {
      return false;
    }

    return $user->getAllPermissions()->contains('name', self::SECTION_ROLES);
  }

}
