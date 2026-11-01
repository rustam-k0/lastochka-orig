<?php

namespace App\GraphQL\Mutations\OperatingCost;

use App\Models\OperatingCost;

final class OperatingCostDelete
{
    public function __invoke($_, array $args)
    {
        OperatingCost::findOrFail($args['id'])->delete();

        return ['code' => 200, 'message' => 'OK'];
    }
}
