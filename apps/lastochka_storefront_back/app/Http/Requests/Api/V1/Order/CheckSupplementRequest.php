<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CheckSupplementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Принимаем deprecated snake_case `store_id` как алиас `storeId` для обратной совместимости.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('store_id') && ! $this->has('storeId')) {
            $this->merge(['storeId' => $this->input('store_id')]);
        }
    }

    public function rules(): array
    {
        return [
            /**
             * ID магазина для проверки наличия активного заказа.
             *
             * @example 1
             */
            'storeId' => ['required', 'integer', Rule::exists('stores', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'storeId.required' => 'Укажите магазин',
            'storeId.exists' => 'Магазин не найден',
        ];
    }

    public function attributes(): array
    {
        return [
            'storeId' => 'магазин',
        ];
    }
}
