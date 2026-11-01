<?php

namespace App\GraphQL\Validators\KpiTarget;

use Nuwave\Lighthouse\Validation\Validator;

final class KpiTargetCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'metric_key' => ['required', 'string', 'max:64'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'target_value' => ['required', 'numeric'],
            'comment' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
