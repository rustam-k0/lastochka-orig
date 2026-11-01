<?php

namespace App\GraphQL\Mutations\Role;

use App\Models\Permission;
use App\Models\Role;
use App\Support\OrderAction;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Exceptions\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class SyncRoleOrderActions
{
    public function __invoke($_, array $args): Role
    {
        $role = Role::findOrFail($args['role_id']);
        $this->applyActions($role, $args['action_keys'] ?? []);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->fresh();
    }

    /**
     * @param array<int, string> $actionKeys полные ключи (orders.cancel, ...)
     */
    public function applyActions(Role $role, array $actionKeys): void
    {
        if ($role->name === Role::ADMIN) {
            throw new AuthorizationException('Нельзя менять доступы администратора.');
        }

        $unknown = array_values(array_diff($actionKeys, OrderAction::all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'action_keys' => ['Неизвестное действие: ' . implode(', ', $unknown)],
            ]);
        }

        $target = collect($actionKeys)
            ->map(fn ($k) => Permission::findOrCreate($k));

        $currentActions = $role->permissions
            ->filter(fn ($p) => Str::startsWith($p->name, 'orders.'));

        $role->revokePermissionTo($currentActions);
        $role->givePermissionTo($target);
    }
}
