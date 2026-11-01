<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Services\Auth\OtpService;
use Illuminate\Foundation\Http\FormRequest;

final class ChangePhoneConfirmRequest extends FormRequest
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
            'newPhone' => ['required', 'string', 'regex:/^\+79\d{9}$/'],
            /**
             * OTP код из 4–6 цифр (длина из codeLength ответа на запрос кода), отправленный на новый номер.
             *
             * @example 123456
             */
            'code' => ['required', 'string', 'regex:/^\d{'.OtpService::MIN_CODE_LENGTH.','.OtpService::MAX_CODE_LENGTH.'}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'newPhone.required' => 'Укажите новый номер телефона',
            'newPhone.regex' => 'Неверный формат номера телефона',
            'code.required' => 'Укажите код подтверждения',
            'code.regex' => 'Код подтверждения должен содержать от '.OtpService::MIN_CODE_LENGTH.' до '.OtpService::MAX_CODE_LENGTH.' цифр',
        ];
    }

    public function normalizedPhone(): string
    {
        $phone = $this->validated('newPhone');

        return preg_replace('/[\s\(\)\-]/', '', $phone);
    }
}
