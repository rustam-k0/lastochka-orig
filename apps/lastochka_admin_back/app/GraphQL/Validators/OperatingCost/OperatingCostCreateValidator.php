<?php

namespace App\GraphQL\Validators\OperatingCost;

use App\Models\OperatingCost;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class OperatingCostCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'category' => ['required', Rule::in(OperatingCost::CATEGORIES)],
            'rule_type' => ['required', Rule::in(OperatingCost::RULE_TYPES)],
            'value' => [
                'required', 'numeric', 'min:0',
                Rule::when($this->arg('rule_type') === 'pct_of_revenue', ['max:100']),
            ],
            'period_start' => ['required', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'comment' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
