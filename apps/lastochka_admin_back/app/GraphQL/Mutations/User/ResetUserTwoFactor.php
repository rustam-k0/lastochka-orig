<?php

namespace App\GraphQL\Mutations\User;

use App\Models\User;

final class ResetUserTwoFactor
{
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function __invoke($_, array $args): array
    {
        $user = User::find($args['id']);
        if (!$user) {
            return ['code' => 404, 'message' => 'User not found'];
        }

        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return ['code' => 200, 'message' => 'OK'];
    }
}
