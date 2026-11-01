<?php

namespace App\GraphQL\Validators\User;

use Nuwave\Lighthouse\Validation\Validator;

class UserForgotPasswordValidator extends Validator
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Укажите email.',
            'email.email'    => 'Укажите корректный email.',
        ];
    }
}
