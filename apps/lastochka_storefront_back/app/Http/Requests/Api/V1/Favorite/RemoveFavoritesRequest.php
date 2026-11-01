<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Favorite;

use Illuminate\Foundation\Http\FormRequest;

final class RemoveFavoritesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * ID товаров для удаления из избранного.
             *
             * @example [15, 16, 17]
             */
            'productIds' => ['required', 'array', 'min:1', 'max:500'],
            'productIds.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'productIds.required' => 'Укажите товары',
            'productIds.array' => 'Укажите товары списком',
            'productIds.min' => 'Укажите хотя бы один товар',
            'productIds.max' => 'Не больше 500 товаров за раз',
            'productIds.*.integer' => 'ID товара должен быть числом',
        ];
    }
}
