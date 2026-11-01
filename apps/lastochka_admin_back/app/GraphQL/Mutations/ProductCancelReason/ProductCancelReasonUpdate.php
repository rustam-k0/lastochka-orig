<?php

namespace App\GraphQL\Mutations\ProductCancelReason;

use App\Models\ProductCancelReason;

final class ProductCancelReasonUpdate
{
    public function __invoke($_, array $args)
    {
        $reason = ProductCancelReason::findOrFail($args['id']);

        $reason->update($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
