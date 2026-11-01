<?php

namespace App\GraphQL\Mutations\OperatingCost;

use App\Models\OperatingCost;

final class OperatingCostCreate
{
    public function __invoke($_, array $args)
    {
        OperatingCost::create($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
