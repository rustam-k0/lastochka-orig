<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Contracts\PhoneSystem;
use App\Exceptions\OtpRateLimitException;
use App\Models\OtpCode;
use App\Services\Auth\OtpService;
use App\Services\IntegrationConfigService;
use App\Services\PhoneServices\SmsAero;
use App\Services\PhoneServices\UCaller;

final class SendOtpAction
{
    public function __construct(
        private OtpService $otpService,
    ) {}

    /**
     * Отправляет OTP код на указанный телефон.
     *
     * @param  string  $method  Метод доставки: 'sms' или 'call'
     *
     * @throws OtpRateLimitException
     */
    public function execute(string $phone, string $method = 'sms'): OtpCode
    {
        // Проверяем rate limit
        if (! $this->otpService->canSendOtp($phone)) {
            throw new OtpRateLimitException;
        }

        // Для тестовых номеров — фиксированный код без SMS
        $testCode = $this->otpService->getTestPhoneCode($phone);
        if ($testCode !== null) {
            return $this->otpService->generate($phone, $testCode, $method);
        }

        // Генерируем OTP код
        $otp = $this->otpService->generate($phone, method: $method);

        // Отправляем через выбранный метод (если не OTP режим)
        $driver = $this->getDriver($method);
        if ($driver !== 'otp') {
            $system = $this->getSystem($method);
            $system->sendPhoneConfirmation($phone, $otp->plain_code);
        }

        return $otp;
    }

    /**
     * Возвращает драйвер для указанного метода доставки.
     */
    private function getDriver(string $method): string
    {
        $type = $method === 'call' ? 'call' : 'sms';
        $config = IntegrationConfigService::get($type);

        return $config?->driver ?? config("services.sms_service.driver");
    }

    /**
     * Возвращает систему отправки по методу доставки.
     *
     * SMS: integration_type = 'sms' (driver: smsaero | otp)
     * Call: integration_type = 'call' (driver: ucaller)
     */
    private function getSystem(string $method): PhoneSystem
    {
        $type = $method === 'call' ? 'call' : 'sms';
        $config = IntegrationConfigService::get($type);
        $driver = $config?->driver ?? config("services.sms_service.driver");

        return match ($driver) {
            'smsaero' => new SmsAero($config?->credentials),
            'ucaller' => new UCaller($config?->credentials),
            default => throw new \Error("No {$type} service available (driver: {$driver})"),
        };
    }
}
