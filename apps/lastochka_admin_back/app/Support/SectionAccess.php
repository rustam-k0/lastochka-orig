<?php

namespace App\Support;

use App\Models\AdminSetting;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Проверка доступа к разделу админки (section.<key>) — единая логика для
 * GraphQL-директивы @section и REST-эндпоинтов (`/action/*`).
 * При выключенной матрице доступов (role_access_matrix_enabled) пропускает
 * всех; admin/superadmin — суперпользователи (защита от самоблокировки),
 * остальные роли ограничены строго грантами section.<key>.
 */
class SectionAccess
{
    public static function enabled(): bool
    {
        return AdminSetting::getValue('role_access_matrix_enabled') === '1';
    }

    public static function allowed(?User $user, string $key): bool
    {
        return self::allowedAny($user, [$key]);
    }

    /**
     * Доступ даёт ЛЮБОЙ из разделов (для полей двойного назначения,
     * напр. ShopUpdate из «Магазины» и «Мой магазин»).
     *
     * @param array<int, string> $keys
     */
    public static function allowedAny(?User $user, array $keys): bool
    {
        if (!self::enabled()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($user->hasRole(Role::ADMIN) || $user->hasRole(Role::SUPERADMIN)) {
            return true;
        }

        foreach ($keys as $key) {
            try {
                if ($user->hasPermissionTo("section.$key")) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                // Гранта нет в БД (миграция разделов не накатилась) — доступа нет
                continue;
            }
        }

        return false;
    }
}
