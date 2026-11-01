<?php

namespace App\GraphQL\Validators\ProductGroupFilter;

use Nuwave\Lighthouse\Validation\Validator;

final class ProductGroupFilterCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
