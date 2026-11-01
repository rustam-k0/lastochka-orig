<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Estimate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddEstimateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'productId' => ['required', 'integer', Rule::exists('products', 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:99999'],
        ];
    }

    public function messages(): array
    {
        return [
            'productId.required' => 'Укажите товар',
            'productId.exists' => 'Товар не найден',
            'quantity.required' => 'Укажите количество',
            'quantity.min' => 'Количество должно быть не меньше 1',
        ];
    }
}
