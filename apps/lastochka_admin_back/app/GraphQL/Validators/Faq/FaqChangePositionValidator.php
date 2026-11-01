<?php

namespace App\GraphQL\Validators\Faq;

use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class FaqChangePositionValidator extends Validator
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('faqs', 'id')->whereNull('deleted_at'),
            ],
            'items.*.position' => ['required', 'integer', 'min:1'],
        ];
    }
}
