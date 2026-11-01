<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangePhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Новый номер телефона в формате +7XXXXXXXXXX.
             *
             * @example +79991234567
             */
            'newPhone' => [
                'required',
                'string',
                'regex:/^\+79\d{9}$/',
                Rule::unique('users', 'phone')->ignore($this->user()->id),
            ],
            /**
             * Метод доставки OTP: sms или call.
             *
             * @example sms
             */
            'method' => ['sometimes', 'string', 'in:sms,call'],
        ];
    }

    public function messages(): array
    {
        return [
            'newPhone.required' => 'Укажите новый номер телефона',
            'newPhone.regex' => 'Неверный формат номера телефона. Используйте формат +79XXXXXXXXX',
            'newPhone.unique' => 'Этот номер телефона уже используется',
        ];
    }

    public function normalizedPhone(): string
    {
        $phone = $this->validated('newPhone');

        return preg_replace('/[\s\(\)\-]/', '', $phone);
    }
}
