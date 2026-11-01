<?php

namespace App\Integrations\Descriptors;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\CredentialField;
use App\Integrations\DriverDescriptor;
use App\Integrations\IntegrationTypeDescriptor;
use App\Integrations\Testers\OtpSmsTester;
use App\Integrations\Testers\SmsAeroTester;

class SmsIntegrationDescriptor extends IntegrationTypeDescriptor
{
    public function type(): string
    {
        return 'sms';
    }

    public function label(): string
    {
        return 'SMS-рассылка';
    }

    public function drivers(): array
    {
        return [
            new DriverDescriptor('otp', 'OTP (код в ответе)'),
            new DriverDescriptor('smsaero', 'SmsAero', [
                new CredentialField('login', 'Email (логин)', 'text'),
                new CredentialField('api_key', 'API ключ', 'password'),
                new CredentialField('sign', 'Имя отправителя', 'text', false, 'SMS Aero'),
            ]),
        ];
    }

    public function getTester(string $driverName): IntegrationTester
    {
        return match ($driverName) {
            'otp' => new OtpSmsTester(),
            'smsaero' => new SmsAeroTester(),
            default => throw new \InvalidArgumentException("Unknown SMS driver: $driverName"),
        };
    }
}
