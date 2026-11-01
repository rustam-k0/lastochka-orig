<?php

namespace App\GraphQL\Validators\ShopAlertRule;

use App\Models\ShopAlertRule;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ShopAlertRuleUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:shop_alert_rules,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'metric_key' => ['nullable', 'string', 'max:64'],
            'comparison' => ['nullable', Rule::in(ShopAlertRule::COMPARISONS)],
            'threshold_value' => ['nullable', 'numeric'],
            'recommended_action_text' => ['nullable', 'string', 'max:2000'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
