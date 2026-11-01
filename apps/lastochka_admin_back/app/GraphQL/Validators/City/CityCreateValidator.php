<?php

namespace App\GraphQL\Validators\City;

use Nuwave\Lighthouse\Validation\Validator;

final class CityCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
