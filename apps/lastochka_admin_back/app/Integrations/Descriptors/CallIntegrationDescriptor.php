<?php

namespace App\Integrations\Descriptors;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\CredentialField;
use App\Integrations\DriverDescriptor;
use App\Integrations\IntegrationTypeDescriptor;
use App\Integrations\Testers\OtpSmsTester;
use App\Integrations\Testers\UCallerTester;

class CallIntegrationDescriptor extends IntegrationTypeDescriptor
{
    public function type(): string
    {
        return 'call';
    }

    public function label(): string
    {
        return 'Телефонные звонки (OTP)';
    }

    public function drivers(): array
    {
        return [
            new DriverDescriptor('otp', 'OTP (код в ответе)'),
            new DriverDescriptor('ucaller', 'uCaller', [
                new CredentialField('secret_key', 'Secret Key', 'password'),
                new CredentialField('service_id', 'Service ID', 'text'),
            ]),
        ];
    }

    public function getTester(string $driverName): IntegrationTester
    {
        return match ($driverName) {
            'otp' => new OtpSmsTester(),
            'ucaller' => new UCallerTester(),
            default => throw new \InvalidArgumentException("Unknown call driver: $driverName"),
        };
    }
}
