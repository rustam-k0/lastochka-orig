<?php

namespace App\GraphQL\Validators\OrderCancelReason;

use Nuwave\Lighthouse\Validation\Validator;

final class OrderCancelReasonUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:order_cancel_reasons,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
