<?php

namespace App\GraphQL\Mutations\OperatingCost;

use App\Models\OperatingCost;

final class OperatingCostUpdate
{
    public function __invoke($_, array $args)
    {
        $cost = OperatingCost::findOrFail($args['id']);
        $cost->update($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
