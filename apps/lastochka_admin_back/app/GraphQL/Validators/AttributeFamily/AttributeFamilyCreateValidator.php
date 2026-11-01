<?php

namespace App\GraphQL\Validators\AttributeFamily;

use Nuwave\Lighthouse\Validation\Validator;

final class AttributeFamilyCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'max:255'],
            'code' => ['nullable', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:attribute_families,code'],
            'attributes' => ['nullable', 'array'],
            'attributes.*.attribute_id' => ['required', 'exists:attributes,id'],
        ];
    }
}
