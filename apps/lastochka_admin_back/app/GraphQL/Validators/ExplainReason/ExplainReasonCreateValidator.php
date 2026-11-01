<?php

namespace App\GraphQL\Validators\ExplainReason;

use Nuwave\Lighthouse\Validation\Validator;

final class ExplainReasonCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
