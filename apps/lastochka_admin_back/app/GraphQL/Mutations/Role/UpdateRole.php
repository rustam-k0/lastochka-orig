<?php

namespace App\GraphQL\Mutations\Role;

use App\Models\Role;

final class UpdateRole
{
    public function __invoke($_, array $args): Role
    {
        $role = Role::findOrFail($args['id']);

        if (array_key_exists('name_ru', $args) && $args['name_ru'] !== null) {
            $role->name_ru = $args['name_ru'];
        }

        if (
            !$role->is_system
            && array_key_exists('scope_type', $args)
            && in_array($args['scope_type'], [Role::SCOPE_GLOBAL, Role::SCOPE_SHOP], true)
        ) {
            $role->scope_type = $args['scope_type'];
        }

        $role->save();

        return $role->fresh();
    }
}
