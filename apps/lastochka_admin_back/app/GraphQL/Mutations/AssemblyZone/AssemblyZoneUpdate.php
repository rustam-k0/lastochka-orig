<?php

namespace App\GraphQL\Mutations\AssemblyZone;

use App\Models\AssemblyZone;

final class AssemblyZoneUpdate
{
    public function __invoke($_, array $args)
    {
        $zone = AssemblyZone::findOrFail($args['id']);
        $zone->update($args);

        return [
            'code'    => 200,
            'message' => 'OK',
            'id'      => $zone->id,
        ];
    }
}
