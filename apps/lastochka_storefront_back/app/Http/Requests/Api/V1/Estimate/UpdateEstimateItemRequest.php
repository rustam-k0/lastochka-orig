<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Estimate;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEstimateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:99999'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'Укажите количество',
            'quantity.min' => 'Количество должно быть не меньше 1',
        ];
    }
}
