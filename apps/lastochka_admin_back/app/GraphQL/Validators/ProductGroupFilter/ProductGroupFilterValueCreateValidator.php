<?php

namespace App\GraphQL\Validators\ProductGroupFilter;

use Nuwave\Lighthouse\Validation\Validator;

final class ProductGroupFilterValueCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'product_group_filter_id' => ['required', 'exists:product_group_filters,id'],
            'value' => ['required', 'string', 'max:100'],
            'image_path' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
