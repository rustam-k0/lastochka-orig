<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Services\Auth\OtpService;
use Illuminate\Foundation\Http\FormRequest;

final class PhoneConfirmRequest extends FormRequest
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
             * OTP код из 4–6 цифр (длина из codeLength ответа на запрос кода).
             *
             * @example 123456
             */
            'code' => ['required', 'string', 'regex:/^\d{'.OtpService::MIN_CODE_LENGTH.','.OtpService::MAX_CODE_LENGTH.'}$/'],
            /**
             * Подтверждение совершеннолетия (18+) с экрана авторизации.
             * true — зафиксировать в профиле; false/отсутствие ранее
             * данное подтверждение НЕ сбрасывает.
             *
             * @example true
             */
            'adultConfirmed' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Укажите номер телефона',
            'phone.regex' => 'Неверный формат номера телефона',
            'code.required' => 'Укажите код подтверждения',
            'code.regex' => 'Код подтверждения должен содержать от '.OtpService::MIN_CODE_LENGTH.' до '.OtpService::MAX_CODE_LENGTH.' цифр',
        ];
    }

    /**
     * Нормализует номер телефона.
     */
    public function normalizedPhone(): string
    {
        $phone = $this->validated('phone');

        return preg_replace('/[\s\(\)\-]/', '', $phone);
    }
}
