<?php

namespace App\GraphQL\Validators\AssemblyZone;

use Nuwave\Lighthouse\Validation\Validator;

final class AssemblyZoneCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'color'       => ['nullable', 'string', 'max:16'],
            'is_active'   => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'position'    => ['nullable', 'integer', 'min:0'],
        ];
    }
}
