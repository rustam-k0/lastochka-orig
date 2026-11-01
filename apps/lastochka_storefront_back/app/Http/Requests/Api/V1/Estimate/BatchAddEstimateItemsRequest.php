<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Estimate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BatchAddEstimateItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.productId' => ['required', 'integer', Rule::exists('products', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99999'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Укажите товары',
            'items.min' => 'Укажите хотя бы один товар',
            'items.max' => 'Максимум 100 товаров за один запрос',
            'items.*.productId.required' => 'Укажите ID товара',
            'items.*.productId.exists' => 'Товар не найден',
            'items.*.quantity.required' => 'Укажите количество',
            'items.*.quantity.min' => 'Количество должно быть не меньше 1',
        ];
    }
}
