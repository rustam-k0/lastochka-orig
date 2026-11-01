<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Cart;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SwitchCartStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Целевой магазин (сетевой каталог): в него переезжают позиции
             * корзины. Если не передан — первый доступный магазин активного
             * адреса пользователя. В обычном режиме игнорируется.
             *
             * @example 3
             */
            // 1 вместо true — как в AddToCartRequest: DatabaseRule кастит bool в
            // строку, на SQLite сравнение с '' у false не матчится.
            'storeId' => ['sometimes', 'nullable', 'integer', Rule::exists('stores', 'id')->where('is_active', 1)],
        ];
    }

    public function messages(): array
    {
        return [
            'storeId.exists' => 'Магазин не найден',
        ];
    }
}
