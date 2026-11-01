<?php

namespace App\GraphQL\Queries\User;

use App\Models\AdminSetting;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use App\Support\OrderAction;
use Illuminate\Support\Str;

final class UserFields
{
    /**
     * Ключи разделов, доступных пользователю через его роли.
     *
     * admin и superadmin — суперпользователи: получают все разделы независимо
     * от грантов (зеркало байпаса SectionDirective, доступы админа в матрице
     * не редактируются). Без этого пункт меню, добавленный миграцией без
     * гранта роли admin, невидим админу при включённой матрице.
     *
     * @return array<int, string>
     */
    public function sectionKeys(User $user): array
    {
        $keys = $user->getAllPermissions()
            ->filter(fn ($p) => Str::startsWith($p->name, 'section.'))
            ->map(fn ($p) => Str::after($p->name, 'section.'));

        if ($user->hasRole(Role::ADMIN) || $user->hasRole(Role::SUPERADMIN)) {
            $keys = $keys->merge(MenuItem::query()->pluck('name'));
        }

        return $keys->unique()->values()->all();
    }

    /**
     * Ключи действий с заказами (orders.*), доступных пользователю через роли.
     * admin и superadmin — все ключи (байпас, зеркально enforcement-слоям).
     *
     * @return array<int, string>
     */
    public function orderActionKeys(User $user): array
    {
        $keys = $user->getAllPermissions()
            ->filter(fn ($p) => Str::startsWith($p->name, 'orders.'))
            ->map(fn ($p) => $p->name);

        if ($user->hasRole(Role::ADMIN) || $user->hasRole(Role::SUPERADMIN)) {
            $keys = $keys->merge(OrderAction::all());
        }

        return $keys->unique()->values()->all();
    }

    public function roleMatrixEnabled(): bool
    {
        return AdminSetting::getValue('role_access_matrix_enabled') === '1';
    }

    public function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(Role::SUPERADMIN);
    }
}
