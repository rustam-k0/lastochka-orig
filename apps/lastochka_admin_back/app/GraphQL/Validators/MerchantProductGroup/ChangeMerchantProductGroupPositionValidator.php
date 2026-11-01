<?php

declare(strict_types=1);

namespace App\GraphQL\Validators\MerchantProductGroup;

use App\Models\MerchantProductGroup;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ChangeMerchantProductGroupPositionValidator extends Validator
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
                Rule::exists((new MerchantProductGroup())->getTable(), 'id')
                    ->whereNull('deleted_at'),
            ],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
