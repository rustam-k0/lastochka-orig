<?php

namespace App\GraphQL\Validators\KpiTarget;

use Nuwave\Lighthouse\Validation\Validator;

final class KpiTargetUpdateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'exists:kpi_targets,id'],
            'metric_key' => ['nullable', 'string', 'max:64'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'target_value' => ['nullable', 'numeric'],
            'comment' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
