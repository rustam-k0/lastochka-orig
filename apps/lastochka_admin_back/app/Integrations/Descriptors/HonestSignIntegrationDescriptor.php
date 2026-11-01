<?php

namespace App\Integrations\Descriptors;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\CredentialField;
use App\Integrations\DriverDescriptor;
use App\Integrations\IntegrationTypeDescriptor;
use App\Integrations\Testers\HonestSignCheckCisTester;

/**
 * Честный знак (MID-266): проверка кодов маркировки при сборке заказа.
 *
 * Платформенный конфиг (shop_id = null): проверяющая ручка одна на всю сеть.
 * Драйвер onec_check_cis — HTTP-сервис 1С Розница (CheckCIS, Basic Auth),
 * читает delivery_back из той же таблицы integration_configs.
 */
class HonestSignIntegrationDescriptor extends IntegrationTypeDescriptor
{
    public const TYPE = 'honest_sign';

    public const DRIVER_ONEC_CHECK_CIS = 'onec_check_cis';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return 'Честный знак';
    }

    public function drivers(): array
    {
        return [
            new DriverDescriptor(self::DRIVER_ONEC_CHECK_CIS, 'Проверка кода через 1С (CheckCIS)', [
                new CredentialField('url', 'URL ручки проверки', 'text', true, 'http://host:port/base/hs/Web_Exchange/CheckCIS'),
                new CredentialField('login', 'Логин (Basic Auth)', 'text', true),
                new CredentialField('password', 'Пароль', 'password'),
            ]),
        ];
    }

    public function getTester(string $driverName): IntegrationTester
    {
        return match ($driverName) {
            self::DRIVER_ONEC_CHECK_CIS => new HonestSignCheckCisTester(),
            default => throw new \InvalidArgumentException("Unknown honest_sign driver: $driverName"),
        };
    }
}
