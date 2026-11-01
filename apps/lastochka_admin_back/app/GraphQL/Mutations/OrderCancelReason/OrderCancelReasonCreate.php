<?php

namespace App\GraphQL\Mutations\OrderCancelReason;

use App\Models\OrderCancelReason;

final class OrderCancelReasonCreate
{
    public function __invoke($_, array $args)
    {
        OrderCancelReason::create($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
