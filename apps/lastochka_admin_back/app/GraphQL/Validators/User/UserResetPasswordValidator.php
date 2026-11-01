<?php

namespace App\GraphQL\Validators\User;

use Nuwave\Lighthouse\Validation\Validator;

class UserResetPasswordValidator extends Validator
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'token'    => ['required', 'string'],
            'email'    => ['bail', 'required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required'     => 'Отсутствует токен восстановления.',
            'email.required'     => 'Укажите email.',
            'email.email'        => 'Укажите корректный email.',
            'password.required'  => 'Укажите новый пароль.',
            'password.min'       => 'Пароль должен содержать минимум 8 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
        ];
    }
}
