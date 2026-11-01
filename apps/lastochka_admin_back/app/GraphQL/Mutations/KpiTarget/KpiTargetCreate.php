<?php

namespace App\GraphQL\Mutations\KpiTarget;

use App\Models\KpiTarget;

final class KpiTargetCreate
{
    public function __invoke($_, array $args)
    {
        KpiTarget::create($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
