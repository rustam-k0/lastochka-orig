<?php

namespace App\GraphQL\Validators\Faq;

use Nuwave\Lighthouse\Validation\Validator;

final class FaqCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
