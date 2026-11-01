<?php

namespace App\GraphQL\Queries\Role;

use App\Models\MenuItem;
use App\Models\Role;
use App\Support\OrderAction;
use Illuminate\Support\Str;

final class RoleFields
{
    /**
     * Список ключей разделов (section.<key> → <key>), доступных роли.
     *
     * admin и superadmin имеют все разделы независимо от грантов (байпас
     * SectionDirective) — матрица показывает строку администратора честно:
     * все тумблеры включены.
     *
     * @return array<int, string>
     */
    public function sectionKeys(Role $role): array
    {
        $keys = $role->permissions
            ->filter(fn ($p) => Str::startsWith($p->name, 'section.'))
            ->map(fn ($p) => Str::after($p->name, 'section.'));

        if (in_array($role->name, [Role::ADMIN, Role::SUPERADMIN], true)) {
            $keys = $keys->merge(MenuItem::query()->pluck('name'));
        }

        return $keys->unique()->values()->all();
    }

    /**
     * Ключи действий с заказами (orders.*), доступных роли.
     * admin и superadmin — все ключи (байпас, зеркально enforcement-слоям).
     *
     * @return array<int, string>
     */
    public function orderActionKeys(Role $role): array
    {
        $keys = $role->permissions
            ->filter(fn ($p) => Str::startsWith($p->name, 'orders.'))
            ->map(fn ($p) => $p->name);

        if (in_array($role->name, [Role::ADMIN, Role::SUPERADMIN], true)) {
            $keys = $keys->merge(OrderAction::all());
        }

        return $keys->unique()->values()->all();
    }
}
