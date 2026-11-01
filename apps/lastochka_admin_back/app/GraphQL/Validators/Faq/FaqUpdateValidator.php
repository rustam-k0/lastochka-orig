<?php

namespace App\GraphQL\Validators\Faq;

use Nuwave\Lighthouse\Validation\Validator;

final class FaqUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:faqs,id'],
            'question' => ['nullable', 'string', 'max:255'],
            'answer' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
