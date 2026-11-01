<?php

namespace App\GraphQL\Validators\ProductGroupFilter;

use Nuwave\Lighthouse\Validation\Validator;

final class ProductGroupFilterUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:product_group_filters,id'],
            'name' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
