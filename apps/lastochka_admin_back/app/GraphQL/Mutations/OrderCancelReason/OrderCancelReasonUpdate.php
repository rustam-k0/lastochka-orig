<?php

namespace App\GraphQL\Mutations\OrderCancelReason;

use App\Models\OrderCancelReason;

final class OrderCancelReasonUpdate
{
    public function __invoke($_, array $args)
    {
        $reason = OrderCancelReason::findOrFail($args['id']);

        $reason->update($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
