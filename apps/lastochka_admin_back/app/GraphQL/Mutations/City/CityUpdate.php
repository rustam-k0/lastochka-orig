<?php

namespace App\GraphQL\Mutations\City;

use App\Models\City;

final class CityUpdate
{
    public function __invoke($_, array $args)
    {
        $city = City::findOrFail($args['id']);

        if (!empty($args['is_default'])) {
            City::resetDefaultExcept($city->id);
        }

        $city->update($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
