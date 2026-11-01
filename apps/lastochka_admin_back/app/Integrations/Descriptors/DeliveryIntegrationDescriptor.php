<?php

namespace App\Integrations\Descriptors;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\CredentialField;
use App\Integrations\DriverDescriptor;
use App\Integrations\IntegrationTypeDescriptor;
use App\Integrations\Testers\YandexGoTester;

class DeliveryIntegrationDescriptor extends IntegrationTypeDescriptor
{
    public function type(): string
    {
        return 'delivery';
    }

    public function label(): string
    {
        return 'Доставка';
    }

    public function drivers(): array
    {
        return [
            new DriverDescriptor(
                name: 'yandex_go',
                label: 'Яндекс Go',
                credentialsSchema: [
                    new CredentialField(
                        field: 'oauth_token',
                        label: 'OAuth-токен Яндекс Go B2B',
                        type: 'password',
                        required: true,
                    ),
                    new CredentialField(
                        field: 'emergency_phone',
                        label: 'Телефон магазина (для курьера)',
                        type: 'text',
                        required: true,
                        placeholder: '+7XXXXXXXXXX',
                    ),
                ],
            ),
        ];
    }

    public function getTester(string $driverName): IntegrationTester
    {
        return match ($driverName) {
            'yandex_go' => new YandexGoTester(),
            default => throw new \InvalidArgumentException("Unknown driver: {$driverName}"),
        };
    }
}
