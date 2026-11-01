<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Payment;

use Illuminate\Foundation\Http\FormRequest;

final class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Сумма возврата.
             *
             * @example 500.00
             */
            'amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Укажите сумму возврата',
            'amount.min' => 'Сумма возврата должна быть больше 0',
        ];
    }
}
