<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class PhoneLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Номер телефона в формате +7XXXXXXXXXX.
             *
             * @example +79991234567
             */
            'phone' => ['required', 'string', 'regex:/^\+79\d{9}$/'],
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
            'phone.required' => 'Укажите номер телефона',
            'phone.regex' => 'Неверный формат номера телефона. Используйте формат +79XXXXXXXXX',
        ];
    }

    /**
     * Нормализует номер телефона.
     */
    public function normalizedPhone(): string
    {
        $phone = $this->validated('phone');

        // Удаляем пробелы, скобки, дефисы
        return preg_replace('/[\s\(\)\-]/', '', $phone);
    }
}
