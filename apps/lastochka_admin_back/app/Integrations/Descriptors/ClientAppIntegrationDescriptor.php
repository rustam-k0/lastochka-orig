<?php

namespace App\Integrations\Descriptors;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\CredentialField;
use App\Integrations\DriverDescriptor;
use App\Integrations\IntegrationTypeDescriptor;
use App\Integrations\Testers\FirebaseFcmTester;

class ClientAppIntegrationDescriptor extends IntegrationTypeDescriptor
{
    public function type(): string
    {
        return 'client_app';
    }

    public function label(): string
    {
        return 'Уведомления: клиентское приложение';
    }

    public function drivers(): array
    {
        return [
            new DriverDescriptor(
                name: 'firebase',
                label: 'Firebase Cloud Messaging',
                credentialsSchema: [
                    new CredentialField(
                        field: 'service_account_json',
                        label: 'Service Account JSON',
                        type: 'file',
                        required: true,
                        placeholder: 'Загрузите JSON-файл сервисного аккаунта',
                    ),
                ],
            ),
        ];
    }

    public function getTester(string $driverName): IntegrationTester
    {
        return match ($driverName) {
            'firebase' => new FirebaseFcmTester(),
            default => throw new \InvalidArgumentException("Unknown driver: {$driverName}"),
        };
    }
}
