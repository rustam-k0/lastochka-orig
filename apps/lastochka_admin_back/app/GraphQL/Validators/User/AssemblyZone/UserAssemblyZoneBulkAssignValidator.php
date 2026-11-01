<?php

namespace App\GraphQL\Validators\User\AssemblyZone;

use App\Models\AssemblyZone;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class UserAssemblyZoneBulkAssignValidator extends Validator
{
    public function rules(): array
    {
        return [
            'user_ids'         => ['required', 'array', 'min:1'],
            'user_ids.*'       => [Rule::exists((new User())->getTable(), 'id')],
            'shop_id'          => ['required', Rule::exists((new Shop())->getTable(), 'id')],
            'assembly_zone_id' => ['nullable', Rule::exists((new AssemblyZone())->getTable(), 'id')],
            'date'             => ['nullable', 'date'],
        ];
    }
}
