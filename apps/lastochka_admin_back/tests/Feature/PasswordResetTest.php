<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private function makeUser(string $role = Role::ADMIN, string $password = 'password123'): User
    {
        $user = User::create([
            'name'     => 'Reset',
            'surname'  => 'Tester',
            'email'    => 'reset_' . uniqid() . '@example.com',
            'phone'    => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt($password),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function forgot(string $email)
    {
        $mutation = '
            mutation ($email: String) {
                UserForgotPassword(email: $email) { code message }
            }
        ';

        return $this->graphQL($mutation, ['email' => $email]);
    }

    private function reset(string $token, string $email, string $password, string $confirmation)
    {
        $mutation = '
            mutation ($token: String, $email: String, $password: String, $password_confirmation: String) {
                UserResetPassword(
                    token: $token
                    email: $email
                    password: $password
                    password_confirmation: $password_confirmation
                ) { code message }
            }
        ';

        return $this->graphQL($mutation, [
            'token'                 => $token,
            'email'                 => $email,
            'password'              => $password,
            'password_confirmation' => $confirmation,
        ]);
    }

    /** @test */
    public function forgot_password_sends_notification_for_existing_user(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $response = $this->forgot($user->email);

        $response->assertJsonPath('data.UserForgotPassword.code', 200);
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /** @test */
    public function forgot_password_unknown_email_returns_error(): void
    {
        Notification::fake();

        $response = $this->forgot('nobody_' . uniqid() . '@example.com');

        $response->assertGraphQLValidationError('email', 'Пользователь с указанным email не найден.');
        Notification::assertNothingSent();
    }

    /** @test */
    public function reset_password_with_valid_token_changes_password_and_revokes_tokens(): void
    {
        $user = $this->makeUser();
        $user->createToken('test')->plainTextToken;
        $this->assertSame(1, $user->tokens()->count());

        $token = Password::broker()->createToken($user);

        $response = $this->reset($token, $user->email, 'newpassword123', 'newpassword123');

        $response->assertJsonPath('data.UserResetPassword.code', 200);
        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
        $this->assertFalse(Hash::check('password123', $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    /** @test */
    public function forgot_password_throttled_on_rapid_repeat(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $this->forgot($user->email)->assertJsonPath('data.UserForgotPassword.code', 200);

        $this->forgot($user->email)->assertGraphQLValidationError(
            'email',
            'Письмо уже отправлено. Пожалуйста, подождите перед повторной попыткой.'
        );
    }

    /** @test */
    public function reset_password_token_cannot_be_reused(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->reset($token, $user->email, 'newpassword123', 'newpassword123')
            ->assertJsonPath('data.UserResetPassword.code', 200);

        // Повторное использование того же токена — ссылка уже недействительна (A2).
        $this->reset($token, $user->email, 'anotherpass456', 'anotherpass456')
            ->assertGraphQLValidationError('email', 'Ссылка восстановления недействительна.');
    }

    /** @test */
    public function reset_password_with_invalid_token_returns_error(): void
    {
        $user = $this->makeUser();

        $response = $this->reset('totally-invalid-token', $user->email, 'newpassword123', 'newpassword123');

        $response->assertGraphQLValidationError('email', 'Ссылка восстановления недействительна.');
        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    /** @test */
    public function reset_password_unknown_email_returns_invalid_link(): void
    {
        $response = $this->reset('some-token', 'nobody_' . uniqid() . '@example.com', 'newpassword123', 'newpassword123');

        $response->assertGraphQLValidationError('email', 'Ссылка восстановления недействительна.');
    }

    /** @test */
    public function reset_password_too_short_fails_validation(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $response = $this->reset($token, $user->email, 'short', 'short');

        $response->assertGraphQLValidationError('password', 'Пароль должен содержать минимум 8 символов.');
        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    /** @test */
    public function reset_password_mismatched_confirmation_fails_validation(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $response = $this->reset($token, $user->email, 'newpassword123', 'different123');

        $response->assertGraphQLValidationError('password', 'Пароли не совпадают.');
        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }
}
