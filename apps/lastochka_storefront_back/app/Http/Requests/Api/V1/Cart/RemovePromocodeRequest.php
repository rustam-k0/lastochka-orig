<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Cart;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RemovePromocodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * ID магазина, у которого удалить промокод. Можно не передавать
             * для platform-wide промокода.
             *
             * @example 1
             */
            'storeId' => ['nullable', 'integer', Rule::exists('stores', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'storeId.exists' => 'Магазин не найден',
        ];
    }
}
