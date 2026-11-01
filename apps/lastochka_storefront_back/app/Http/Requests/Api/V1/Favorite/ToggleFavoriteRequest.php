<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Favorite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ToggleFavoriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * ID товара для добавления/удаления из избранного.
             *
             * @example 15
             */
            'productId' => ['required', 'integer', Rule::exists('products', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'productId.required' => 'Укажите товар',
            'productId.exists' => 'Товар не найден',
        ];
    }
}
