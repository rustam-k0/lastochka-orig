<?php

namespace App\GraphQL\Mutations\KpiTarget;

use App\Models\KpiTarget;

final class KpiTargetDelete
{
    public function __invoke($_, array $args)
    {
        KpiTarget::findOrFail($args['id'])->delete();

        return ['code' => 200, 'message' => 'OK'];
    }
}
