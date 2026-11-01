<?php

namespace App\GraphQL\Mutations\ProductCancelReason;

use App\Models\ProductCancelReason;

final class ProductCancelReasonCreate
{
    public function __invoke($_, array $args)
    {
        ProductCancelReason::create($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
