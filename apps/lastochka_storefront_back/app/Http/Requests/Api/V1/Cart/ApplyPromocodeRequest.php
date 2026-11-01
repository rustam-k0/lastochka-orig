<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Cart;

use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApplyPromocodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * ID магазина, к которому применить промокод. Можно не передавать
             * для platform-wide промокодов.
             *
             * @example 1
             */
            'storeId' => [
                app(ModuleConfigService::class)->isMarketplaceMode() ? 'nullable' : 'required',
                'integer',
                Rule::exists('stores', 'id'),
            ],
            /**
             * Код промокода.
             *
             * @example PROMO10
             */
            'promocode' => ['required', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'storeId.exists' => 'Магазин не найден',
            'promocode.required' => 'Укажите промокод',
            'promocode.max' => 'Промокод слишком длинный',
        ];
    }
}
