<?php

namespace App\GraphQL\Mutations\KpiTarget;

use App\Models\KpiTarget;

final class KpiTargetUpdate
{
    public function __invoke($_, array $args)
    {
        $target = KpiTarget::findOrFail($args['id']);
        $target->update($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
