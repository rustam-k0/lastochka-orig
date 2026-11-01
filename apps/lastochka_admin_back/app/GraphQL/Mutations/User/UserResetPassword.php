<?php

namespace App\GraphQL\Mutations\User;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Nuwave\Lighthouse\Exceptions\ValidationException;

class UserResetPassword
{
    /**
     * Установка нового пароля по токену из письма восстановления.
     *
     * Ошибки бросаются как ValidationException (category=validation),
     * чтобы фронт (parseGqlErrors) показал их под полем email.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function __invoke($_, array $args): array
    {
        $status = Password::broker()->reset(
            [
                'email'                 => $args['email'],
                'password'              => $args['password'],
                'password_confirmation' => $args['password_confirmation'],
                'token'                 => $args['token'],
            ],
            function (User $user, string $password): void {
                $user->password = bcrypt($password);
                $user->save();

                // Бизнес-правило: предыдущий пароль становится недействительным.
                // Отзываем все активные Sanctum-токены — пользователь входит заново.
                // (в таблице users этого форка нет колонки remember_token,
                //  инвалидация сессий обеспечивается отзывом токенов.)
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        // A2. Любой неуспех (истёкший / уже использованный / некорректный токен,
        // несуществующий пользователь) — единый текст по требованию ТЗ.
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Ссылка восстановления недействительна.'],
            ]);
        }

        return [
            'code'    => 200,
            'message' => 'Пароль успешно изменён. Войдите с новым паролем.',
        ];
    }
}
