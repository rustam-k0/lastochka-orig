<?php

namespace App\GraphQL\Mutations\User;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Nuwave\Lighthouse\Exceptions\ValidationException;

class UserForgotPassword
{
    /**
     * Запрос на восстановление пароля.
     *
     * Проверяет наличие пользователя по email и отправляет письмо
     * со ссылкой на установку нового пароля (стандартный брокер Laravel).
     *
     * Ошибки бросаются как ValidationException (category=validation),
     * чтобы фронт (parseGqlErrors) показал их под полем email.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function __invoke($_, array $args): array
    {
        $email = $args['email'];

        // A1. Email не найден — по требованию ТЗ сообщаем явно (Eloquent учитывает soft-delete).
        $user = User::whereEmail($email)->first();
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Пользователь с указанным email не найден.'],
            ]);
        }

        $status = Password::broker()->sendResetLink(['email' => $email]);

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => ['Письмо уже отправлено. Пожалуйста, подождите перед повторной попыткой.'],
            ]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => ['Не удалось отправить письмо для восстановления пароля.'],
            ]);
        }

        return [
            'code'    => 200,
            'message' => 'Инструкция по восстановлению пароля отправлена на указанный email.',
        ];
    }
}
