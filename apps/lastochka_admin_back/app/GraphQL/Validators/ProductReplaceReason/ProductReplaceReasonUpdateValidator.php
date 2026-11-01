<?php

namespace App\GraphQL\Validators\ProductReplaceReason;

use Nuwave\Lighthouse\Validation\Validator;

final class ProductReplaceReasonUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:product_replace_reasons,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
