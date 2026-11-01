<?php

namespace App\GraphQL\Mutations\Fcm;

use App\Models\UserFcmToken;

final class RemoveFcmToken
{
    public function __invoke($_, array $args)
    {
        UserFcmToken::where('token', $args['token'])
            ->where('user_id', \Auth::id())
            ->delete();

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
