<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Cart;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BatchAddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Массив товаров для добавления в корзину.
             */
            'items' => ['required', 'array', 'min:1', 'max:100'],
            /**
             * ID товара.
             *
             * @example 1
             */
            'items.*.productId' => ['required', 'integer'],
            /**
             * Количество товара (от 1 до 99999).
             *
             * @example 1
             */
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99999'],
            /**
             * Магазин покупки для всех позиций (только сетевой каталог).
             * Если не передан — первый доступный магазин активного адреса.
             *
             * @example 3
             */
            'storeId' => ['sometimes', 'nullable', 'integer', Rule::exists('stores', 'id')->where('is_active', 1)],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Укажите товары',
            'items.array' => 'Товары должны быть массивом',
            'items.min' => 'Укажите хотя бы один товар',
            'items.max' => 'Максимум 100 товаров за один запрос',
            'items.*.productId.required' => 'Укажите ID товара',
            'items.*.productId.exists' => 'Товар не найден',
            'items.*.quantity.required' => 'Укажите количество',
            'items.*.quantity.min' => 'Количество должно быть не меньше 1',
            'items.*.quantity.max' => 'Максимальное количество — 99999',
            'storeId.exists' => 'Магазин не найден',
        ];
    }
}
