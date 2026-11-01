<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;

final class SupplementOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Принимаем deprecated snake_case `idempotency_key` как алиас `idempotencyKey` для обратной совместимости.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('idempotency_key') && ! $this->has('idempotencyKey')) {
            $this->merge(['idempotencyKey' => $this->input('idempotency_key')]);
        }
    }

    public function rules(): array
    {
        return [
            /**
             * Ключ идемпотентности для предотвращения дублирования.
             *
             * @example 550e8400-e29b-41d4-a716-446655440000
             */
            'idempotencyKey' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotencyKey.max' => 'Ключ идемпотентности слишком длинный',
        ];
    }
}
