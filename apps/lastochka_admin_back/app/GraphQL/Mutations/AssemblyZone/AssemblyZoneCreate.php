<?php

namespace App\GraphQL\Mutations\AssemblyZone;

use App\Models\AssemblyZone;

final class AssemblyZoneCreate
{
    public function __invoke($_, array $args)
    {
        $zone = AssemblyZone::create($args);

        return [
            'code'    => 200,
            'message' => 'OK',
            'id'      => $zone->id,
        ];
    }
}
