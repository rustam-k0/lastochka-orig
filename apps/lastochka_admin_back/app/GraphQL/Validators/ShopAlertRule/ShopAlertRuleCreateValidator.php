<?php

namespace App\GraphQL\Validators\ShopAlertRule;

use App\Models\ShopAlertRule;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ShopAlertRuleCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'metric_key' => ['required', 'string', 'max:64'],
            'comparison' => ['required', Rule::in(ShopAlertRule::COMPARISONS)],
            'threshold_value' => ['required', 'numeric'],
            'recommended_action_text' => ['nullable', 'string', 'max:2000'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
