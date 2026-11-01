<?php

namespace App\GraphQL\Mutations\ProductReplaceReason;

use App\Models\ProductReplaceReason;

final class ProductReplaceReasonDelete
{
    public function __invoke($_, array $args)
    {
        $reason = ProductReplaceReason::findOrFail($args['id']);

        $reason->delete();

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
