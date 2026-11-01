<?php

namespace App\GraphQL\Validators\AttributeFamily;

use Nuwave\Lighthouse\Validation\Validator;

final class AttributeFamilyUpdateValidator extends Validator
{
    public function rules(): array
    {
        $id = $this->arg('id');

        return [
            'title' => ['sometimes', 'max:255'],
            'code' => ['nullable', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', "unique:attribute_families,code,{$id}"],
            'attributes' => ['nullable', 'array'],
            'attributes.*.attribute_id' => ['required', 'exists:attributes,id'],
        ];
    }
}
