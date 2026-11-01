<?php

namespace App\GraphQL\Validators\OperatingCost;

use App\Models\OperatingCost;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class OperatingCostUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:store_operating_costs,id'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'category' => ['nullable', Rule::in(OperatingCost::CATEGORIES)],
            'rule_type' => ['nullable', Rule::in(OperatingCost::RULE_TYPES)],
            'value' => [
                'nullable', 'numeric', 'min:0',
                Rule::when($this->arg('rule_type') === 'pct_of_revenue', ['max:100']),
            ],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
