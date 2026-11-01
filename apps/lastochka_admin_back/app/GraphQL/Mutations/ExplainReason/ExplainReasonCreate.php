<?php

namespace App\GraphQL\Mutations\ExplainReason;

use App\Models\ExplainReason;

final class ExplainReasonCreate
{
    public function __invoke($_, array $args)
    {
        ExplainReason::create($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
