<?php

namespace App\Integrations\Descriptors;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\CredentialField;
use App\Integrations\DriverDescriptor;
use App\Integrations\IntegrationTypeDescriptor;
use App\Integrations\Testers\CommerceMLTester;

class OneCIntegrationDescriptor extends IntegrationTypeDescriptor
{
    public function type(): string
    {
        return '1c';
    }

    public function label(): string
    {
        return '1С:Торговое предприятие';
    }

    public function drivers(): array
    {
        return [
            new DriverDescriptor('commerceml', 'CommerceML 2.x', [
                new CredentialField('login', 'Логин обмена (Basic Auth)', 'text', true, '1c_exchange'),
                new CredentialField('password', 'Пароль обмена', 'password'),
            ]),
        ];
    }

    public function getTester(string $driverName): IntegrationTester
    {
        return match ($driverName) {
            'commerceml' => new CommerceMLTester(),
            default => throw new \InvalidArgumentException("Unknown 1C driver: $driverName"),
        };
    }
}
