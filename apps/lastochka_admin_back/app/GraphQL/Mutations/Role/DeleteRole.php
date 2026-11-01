<?php

namespace App\GraphQL\Mutations\Role;

use App\Models\Role;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

final class DeleteRole
{
    public function __invoke($_, array $args): array
    {
        $role = Role::findOrFail($args['id']);

        if ($role->is_system) {
            throw new AuthorizationException('Системную роль удалить нельзя.');
        }

        if ($role->users()->exists()) {
            throw new AuthorizationException('Нельзя удалить роль, назначенную пользователям.');
        }

        $role->delete();

        return ['code' => 200, 'message' => 'OK'];
    }
}
