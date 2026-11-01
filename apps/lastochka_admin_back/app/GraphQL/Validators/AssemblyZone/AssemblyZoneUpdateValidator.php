<?php

namespace App\GraphQL\Validators\AssemblyZone;

use App\Models\AssemblyZone;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class AssemblyZoneUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id'          => ['required', Rule::exists((new AssemblyZone())->getTable(), 'id')],
            'title'       => ['nullable', 'string', 'max:255'],
            'color'       => ['nullable', 'string', 'max:16'],
            'is_active'   => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'position'    => ['nullable', 'integer', 'min:0'],
        ];
    }
}
