<?php

namespace App\GraphQL\Mutations\City;

use App\Models\City;

final class CityCreate
{
    public function __invoke($_, array $args)
    {
        $city = City::create($args);

        // Сброс прежнего дефолта — только после создания, чтобы исключить из
        // сброса сам новый город.
        if (!empty($args['is_default'])) {
            City::resetDefaultExcept($city->id);
        }

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
