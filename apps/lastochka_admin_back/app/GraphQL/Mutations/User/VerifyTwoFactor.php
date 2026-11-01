<?php

namespace App\GraphQL\Mutations\User;

use App\Models\User;
use App\Services\TwoFactor\TwoFactorService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\RateLimiter;

class VerifyTwoFactor
{
    public function __construct(private TwoFactorService $twoFactor)
    {
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function __invoke($_, array $args): array
    {
        $userId = $this->twoFactor->resolveChallenge((string) ($args['challenge'] ?? ''));
        if (!$userId) {
            throw new Error('Сессия подтверждения истекла. Войдите заново.');
        }

        $throttleKey = 'verify-2fa:' . $userId;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw new Error('Слишком много попыток. Попробуйте позже.');
        }

        $user = User::find($userId);
        if (!$user || !$user->two_factor_secret) {
            throw new Error('Неверный код.');
        }

        $timestamp = $this->twoFactor->verifyAndGetTimestamp(
            $user->two_factor_secret,
            (string) ($args['code'] ?? ''),
            $user->two_factor_last_otp_timestamp
        );

        if ($timestamp === false) {
            RateLimiter::hit($throttleKey, 60);
            throw new Error('Неверный код.');
        }

        RateLimiter::clear($throttleKey);

        $user->two_factor_last_otp_timestamp = $timestamp;
        if (!$user->two_factor_confirmed_at) {
            $user->two_factor_confirmed_at = now();
        }
        $user->save();

        return [
            'code'       => 200,
            'message'    => "You're logged in!",
            'two_factor' => 'verified',
            'token'      => $user->createToken(config('app.name'))->plainTextToken,
            'user'       => $user,
        ];
    }
}
