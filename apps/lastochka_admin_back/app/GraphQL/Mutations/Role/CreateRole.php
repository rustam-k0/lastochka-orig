<?php

namespace App\GraphQL\Mutations\Role;

use App\Models\Role;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

final class CreateRole
{
    public function __invoke($_, array $args): Role
    {
        $scope = in_array($args['scope_type'], [Role::SCOPE_GLOBAL, Role::SCOPE_SHOP], true)
            ? $args['scope_type']
            : Role::SCOPE_GLOBAL;

        $role = Role::create([
            'name'       => 'role_' . Str::lower(Str::random(12)),
            'name_ru'    => $args['name_ru'],
            'scope_type' => $scope,
            'is_system'  => false,
        ]);

        (new SyncRoleSections())->applySections($role, $args['section_keys'] ?? []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->fresh();
    }
}
