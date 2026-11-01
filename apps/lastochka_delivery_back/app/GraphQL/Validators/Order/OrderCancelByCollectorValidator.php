<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Assembly;
use App\Models\OrderCancelReason;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderCancelByCollectorValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'assembly_id' => ['bail', 'required', function ($attribute, $value, $fail) {
                $exists = Assembly::auth()->whereId($value)->exists();
                if (!$exists) {
                    $fail('Сборка не найдена');
                }
            }],
            'reason_id' => ['nullable', 'integer', Rule::exists((new OrderCancelReason())->getTable(), 'id')->where('is_active', true)],
            'custom_reason' => ['nullable', 'string', 'max:1000'],
        ];

        // Причина отмены обязательна: либо reason_id из справочника, либо custom_reason.
        if (empty($this->arg('reason_id')) && empty(trim((string) $this->arg('custom_reason')))) {
            $rules['reason_id'][] = 'required';
        }

        return $rules;
    }
}
