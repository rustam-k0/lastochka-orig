<?php

namespace App\GraphQL\Mutations\Role;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

final class SyncRoleSections
{
    /** Ключ раздела «Роли и доступы» (menu_items.name). */
    private const SECTION_ROLES_KEY = 'roles';

    public function __invoke($_, array $args): Role
    {
        $role = Role::findOrFail($args['role_id']);
        $this->applySections($role, $args['section_keys'] ?? []);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->fresh();
    }

    /**
     * @param array<int, string> $sectionKeys
     */
    public function applySections(Role $role, array $sectionKeys): void
    {
        if ($role->name === Role::ADMIN) {
            throw new AuthorizationException('Нельзя менять доступы администратора.');
        }

        $this->guardSelfLockout($role, $sectionKeys);

        $target = collect($sectionKeys)
            ->map(fn ($k) => Permission::findOrCreate("section.$k"));

        $currentSection = $role->permissions
            ->filter(fn ($p) => Str::startsWith($p->name, 'section.'));

        $role->revokePermissionTo($currentSection);
        $role->givePermissionTo($target);
    }

    /**
     * Роль с доступом к матрице не может снять раздел «Роли и доступы» сама с
     * себя: вернуть галочку было бы уже некому, кроме админа через БД (MID-210).
     * Админа и супер-админа не касается — они байпасят гранты.
     *
     * @param array<int, string> $sectionKeys
     */
    private function guardSelfLockout(Role $role, array $sectionKeys): void
    {
        if (in_array(self::SECTION_ROLES_KEY, $sectionKeys, true)) {
            return;
        }

        /** @var \App\Models\User|null $user */
        $user = auth()->guard('api')->user() ?? auth()->user();

        if ($user === null
            || $user->hasRole(Role::ADMIN)
            || $user->hasRole(Role::SUPERADMIN)
            || !$user->roles->contains('id', $role->id)) {
            return;
        }

        $hadSection = $role->permissions
            ->contains('name', 'section.' . self::SECTION_ROLES_KEY);

        if ($hadSection) {
            throw new AuthorizationException(
                'Нельзя снять раздел «Роли и доступы» со своей роли — доступ вернуть будет некому.'
            );
        }
    }
}
