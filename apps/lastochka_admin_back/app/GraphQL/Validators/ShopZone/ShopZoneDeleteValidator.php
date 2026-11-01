<?php

namespace App\GraphQL\Validators\ShopZone;

use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ShopZoneDeleteValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'id'=>['required',Rule::exists('shop_zones')]
        ];
    }
}
