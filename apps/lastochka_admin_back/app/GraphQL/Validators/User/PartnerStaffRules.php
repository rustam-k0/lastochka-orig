<?php

namespace App\GraphQL\Validators\User;

use App\Models\Role;
use App\Models\User;
use App\Support\PartnerStaffAccess;
use Closure;

/**
 * Дополнительные правила валидации сотрудников для партнёра-селлера (shop-scope).
 * Для глобального админа правила — no-op (он не ограничен).
 */
trait PartnerStaffRules
{
  /**
   * Партнёр может назначать только роль сборщика или курьера.
   */
  protected function partnerRoleRule(): Closure
  {
    return function (string $attribute, $value, Closure $fail): void {
      $authUser = \Auth::user();
      if (!PartnerStaffAccess::isPartner($authUser)) {
        return;
      }

      if (!PartnerStaffAccess::roleAllowed(Role::find($value)?->name)) {
        $fail('Можно назначить только роль сборщика или курьера.');
      }
    };
  }

  /**
   * Партнёр может привязывать сотрудника только к своим магазинам.
   */
  protected function partnerShopRule(): Closure
  {
    return function (string $attribute, $value, Closure $fail): void {
      $authUser = \Auth::user();
      if (!PartnerStaffAccess::isPartner($authUser)) {
        return;
      }

      if (!in_array((int) $value, PartnerStaffAccess::ownShopIds($authUser), true)) {
        $fail('Магазин недоступен.');
      }
    };
  }

  /**
   * Партнёр может редактировать/удалять только сотрудников своих магазинов
   * (сборщиков и курьеров).
   */
  protected function partnerOwnStaffRule(): Closure
  {
    return function (string $attribute, $value, Closure $fail): void {
      $authUser = \Auth::user();
      if (!PartnerStaffAccess::isPartner($authUser)) {
        return;
      }

      $accessible = User::query()->whereKey($value)->accessible()->exists();
      if (!$accessible) {
        $fail('Нет доступа к этому сотруднику.');
      }
    };
  }
}
