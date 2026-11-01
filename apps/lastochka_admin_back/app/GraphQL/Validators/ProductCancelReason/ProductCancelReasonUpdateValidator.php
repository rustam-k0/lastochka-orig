<?php

namespace App\GraphQL\Validators\ProductCancelReason;

use Nuwave\Lighthouse\Validation\Validator;

final class ProductCancelReasonUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:product_cancel_reasons,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
