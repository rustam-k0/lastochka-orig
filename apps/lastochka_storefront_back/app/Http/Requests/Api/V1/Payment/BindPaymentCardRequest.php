<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Payment;

use App\Http\Requests\Concerns\ResolvesReturnUrl;
use App\Rules\ReturnUrlRule;
use App\Support\Payment\ReturnUrl;
use Illuminate\Foundation\Http\FormRequest;

final class BindPaymentCardRequest extends FormRequest
{
    use ResolvesReturnUrl;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Адрес, на который вернуть клиента после подтверждения карты
             * (ЮKassa confirmation.return_url). Относительный путь витрины,
             * абсолютный адрес с разрешённого домена либо диплинк приложения.
             * Не передан — адрес по умолчанию из настроек.
             *
             * @example /profile/cards
             */
            'returnUrl' => ['nullable', 'string', 'max:'.ReturnUrl::MAX_LENGTH, new ReturnUrlRule],
        ];
    }

    public function attributes(): array
    {
        return [
            'returnUrl' => 'адрес возврата',
        ];
    }
}
