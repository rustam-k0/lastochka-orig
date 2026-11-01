<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Проверка права сотрудника на действие с заказом при ВКЛЮЧЁННОЙ матрице
 * доступов (role_access_matrix_enabled=1). Поведение при выключенном флаге
 * каждая точка вызова определяет сама (в delivery — AdminPolicy::onlyAdmin).
 */
class OrderActionAccess
{
  public static function allowedUnderMatrix(User $user, string $permission): bool
  {
    // admin и superadmin — суперпользователи (защита от самоблокировки),
    // зеркально SectionDirective в admin_back
    if ($user->hasRole(Role::ADMIN) || $user->hasRole(Role::SUPERADMIN)) {
      return true;
    }

    try {
      return $user->hasPermissionTo($permission);
    } catch (PermissionDoesNotExist) {
      // Права ещё не засеяны миграцией admin_back — fail closed
      return false;
    }
  }
}
