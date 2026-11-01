<?php

namespace App\GraphQL\Mutations\OrderCancelReason;

use App\Models\OrderCancelReason;

final class OrderCancelReasonDelete
{
    public function __invoke($_, array $args)
    {
        $reason = OrderCancelReason::findOrFail($args['id']);

        $reason->delete();

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
