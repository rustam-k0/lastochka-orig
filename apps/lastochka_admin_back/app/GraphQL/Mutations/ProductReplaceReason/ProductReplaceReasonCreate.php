<?php

namespace App\GraphQL\Mutations\ProductReplaceReason;

use App\Models\ProductReplaceReason;

final class ProductReplaceReasonCreate
{
    public function __invoke($_, array $args)
    {
        ProductReplaceReason::create($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
