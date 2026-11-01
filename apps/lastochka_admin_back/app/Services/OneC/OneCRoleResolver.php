<?php

declare(strict_types=1);

namespace App\Services\OneC;

use App\Models\Role;

/**
 * Роль сотрудника из 1С (MID-306): код роли платформы (collector, courier, …)
 * или её русское название из админки, без учёта регистра. Код приоритетнее названия.
 *
 * Резолвятся только роли из белого списка config('onec.employee_roles'); superadmin и api
 * не назначаются никогда, даже если их вписали в конфиг. Роли читаются на каждый вызов:
 * консьюмер живёт долго, а роли создают в админке.
 */
final class OneCRoleResolver
{
  private const FORBIDDEN = [Role::SUPERADMIN, Role::API];

  public function resolve(mixed $raw): ?Role
  {
    if (!is_string($raw)) {
      return null;
    }

    $needle = mb_strtolower(trim($raw));

    if ($needle === '') {
      return null;
    }

    $roles = Role::query()
      ->whereNotIn('name', self::FORBIDDEN)
      ->orderBy('id')
      ->get()
      ->filter(fn (Role $role) => $this->isAllowedRoleName((string) $role->name))
      ->values();

    return $roles->first(fn (Role $role) => mb_strtolower((string) $role->name) === $needle)
      ?? $roles->first(fn (Role $role) => mb_strtolower(trim((string) $role->name_ru)) === $needle);
  }

  /**
   * Код роли входит в белый список 1С и не является superadmin/api. Регистр не важен.
   */
  public function isAllowedRoleName(string $name): bool
  {
    $name = mb_strtolower(trim($name));

    if ($name === '' || in_array($name, self::FORBIDDEN, true)) {
      return false;
    }

    foreach ((array) config('onec.employee_roles', []) as $allowed) {
      if (is_string($allowed) && mb_strtolower(trim($allowed)) === $name) {
        return true;
      }
    }

    return false;
  }
}
