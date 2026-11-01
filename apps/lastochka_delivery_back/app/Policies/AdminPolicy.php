<?php

namespace App\Policies;

use App\Models\AdminSetting;
use App\Models\User;
use App\Models\Role;
use App\Support\OrderActionAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

class AdminPolicy
{
  use HandlesAuthorization;

  /**
   * Есть ли у пользователя права администратора
   */
  public function onlyAdmin(User $user): bool
  {
    return $user->hasRole(Role::ADMIN);
  }

  public function onShift(User $user): bool
  {
    return $user->shift()->exists();
  }

  public function driverOnShift(User $user):bool
  {
    if (!$user->hasRole(Role::COURIER)){
      return false;
    }

    return $this->onShift($user);
  }

  public function collectorOnShift(User $user): bool
  {
    if (!$user->hasRole(Role::COLLECTOR)) {
      return false;
    }

    return $this->onShift($user);
  }

  /**
   * Назначение/смена сборщика из админки. При выключенной матрице — только
   * админ (старое onlyAdmin), при включённой — по праву orders.assign_collector.
   */
  public function assignCollector(User $user): bool
  {
    return $this->canOrderAction($user, 'orders.assign_collector');
  }

  /**
   * Назначение/смена курьера из админки.
   */
  public function assignCourier(User $user): bool
  {
    return $this->canOrderAction($user, 'orders.assign_courier');
  }

  private function canOrderAction(User $user, string $permission): bool
  {
    if (AdminSetting::getValue('role_access_matrix_enabled') !== '1') {
      return $this->onlyAdmin($user);
    }

    return OrderActionAccess::allowedUnderMatrix($user, $permission);
  }

}
