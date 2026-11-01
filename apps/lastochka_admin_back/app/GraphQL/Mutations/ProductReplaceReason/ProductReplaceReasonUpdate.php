<?php

namespace App\GraphQL\Mutations\ProductReplaceReason;

use App\Models\ProductReplaceReason;

final class ProductReplaceReasonUpdate
{
    public function __invoke($_, array $args)
    {
        $reason = ProductReplaceReason::findOrFail($args['id']);

        $reason->update($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
