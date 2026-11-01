<?php

namespace App\GraphQL\Mutations\Fcm;

use App\Models\UserFcmToken;

final class SaveFcmToken
{
    public function __invoke($_, array $args)
    {
        $userId = \Auth::id();
        $token = $args['token'];
        $deviceType = $args['device_type'] ?? null;

        // Atomic upsert по token. Перезаписывает user_id если ранее принадлежал
        // другому юзеру (старый delete+updateOrCreate ловил race condition → 1062).
        $now = now();

        UserFcmToken::upsert(
            [[
                'token' => $token,
                'user_id' => $userId,
                'device_type' => $deviceType,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            uniqueBy: ['token'],
            update: ['user_id', 'device_type', 'updated_at']
        );

        return UserFcmToken::where('token', $token)->first();
    }
}
