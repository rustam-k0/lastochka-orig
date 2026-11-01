<?php

namespace App\GraphQL\Validators\ProductGroupFilter;

use Nuwave\Lighthouse\Validation\Validator;

final class ProductGroupFilterValueUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:product_group_filter_values,id'],
            'value' => ['nullable', 'string', 'max:100'],
            'image_path' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
