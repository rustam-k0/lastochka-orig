<?php

namespace App\GraphQL\Mutations\ExplainReason;

use App\Models\ExplainReason;

final class ExplainReasonDelete
{
    public function __invoke($_, array $args)
    {
        $reason = ExplainReason::findOrFail($args['id']);

        $reason->delete();

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
