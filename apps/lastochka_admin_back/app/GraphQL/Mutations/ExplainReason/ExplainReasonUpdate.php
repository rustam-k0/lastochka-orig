<?php

namespace App\GraphQL\Mutations\ExplainReason;

use App\Models\ExplainReason;

final class ExplainReasonUpdate
{
    public function __invoke($_, array $args)
    {
        $reason = ExplainReason::findOrFail($args['id']);

        $reason->update($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
