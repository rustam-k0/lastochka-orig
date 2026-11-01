<?php

namespace App\GraphQL\Mutations\ProductCancelReason;

use App\Models\ProductCancelReason;

final class ProductCancelReasonDelete
{
    public function __invoke($_, array $args)
    {
        $reason = ProductCancelReason::findOrFail($args['id']);

        $reason->delete();

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
