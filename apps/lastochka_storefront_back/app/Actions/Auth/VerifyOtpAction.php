<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\AuthResultData;
use App\Exceptions\InvalidOtpException;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Loyalty\LoyaltyCardService;
use Illuminate\Support\Facades\DB;

final class VerifyOtpAction
{
    public function __construct(
        private OtpService $otpService,
        private LoyaltyCardService $loyaltyCards,
    ) {}

    /**
     * Подтверждает OTP код и возвращает токен авторизации.
     *
     * adultConfirmed=true с экрана авторизации фиксирует подтверждение
     * совершеннолетия в профиле. false/null ранее данное подтверждение
     * не сбрасывает — отказ на экране логина не является отзывом.
     *
     * @throws InvalidOtpException
     */
    public function execute(string $phone, string $code, ?bool $adultConfirmed = null): AuthResultData
    {
        // Проверка кода выполняется ВНЕ транзакции ниже: verify() при неверном
        // вводе инкрементит счётчик попыток и бросает исключение, а если бы это
        // происходило внутри DB::transaction, инкремент откатывался бы вместе с
        // остальными изменениями и лимит MAX_ATTEMPTS никогда бы не срабатывал.
        $otp = $this->otpService->verify($phone, $code);

        return DB::transaction(function () use ($phone, $otp, $adultConfirmed) {
            // Помечаем код использованным и инвалидируем соседние активные коды номера
            $this->otpService->consume($otp);

            // Находим или создаём пользователя (включая soft-deleted — восстанавливаем)
            $user = User::withTrashed()->where('phone', $phone)->first();

            if ($user && $user->trashed()) {
                $user->restore();
            } elseif (! $user) {
                $user = User::create(['phone' => $phone]);
                // Авто-создание карты лояльности при регистрации (LASTOCHKI-JAMI-12013)
                $this->loyaltyCards->ensureCard($user);
            }

            if ($adultConfirmed === true && ! $user->adult_confirmed) {
                $user->update(['adult_confirmed' => true]);
            }

            // Создаём токен Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            return new AuthResultData(
                token: $token,
                user: $user,
            );
        });
    }
}
