<?php

namespace App\GraphQL\Validators\ExplainReason;

use Nuwave\Lighthouse\Validation\Validator;

final class ExplainReasonUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:explain_reasons,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
