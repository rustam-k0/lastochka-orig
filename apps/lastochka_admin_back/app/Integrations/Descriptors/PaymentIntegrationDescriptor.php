<?php

namespace App\Integrations\Descriptors;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\CredentialField;
use App\Integrations\DriverDescriptor;
use App\Integrations\IntegrationTypeDescriptor;
use App\Integrations\Testers\YooKassaTester;

class PaymentIntegrationDescriptor extends IntegrationTypeDescriptor
{
    public function type(): string
    {
        return 'payment';
    }

    public function label(): string
    {
        return 'Платёжная система';
    }

    public function drivers(): array
    {
        return [
            new DriverDescriptor('yookassa', 'ЮKassa', [
                new CredentialField('mode', 'Режим работы', 'select', true, null, [
                    ['value' => 'test', 'label' => 'Тестовый'],
                    ['value' => 'live', 'label' => 'Боевой'],
                ]),
                new CredentialField('test_shop_id', 'Тестовый Shop ID', 'text'),
                new CredentialField('test_secret_key', 'Тестовый секретный ключ', 'password'),
                new CredentialField('live_shop_id', 'Боевой Shop ID', 'text', false),
                new CredentialField('live_secret_key', 'Боевой секретный ключ', 'password', false),
                new CredentialField('send_receipt', 'Отправлять чек (онлайн-касса)', 'checkbox', false),
                // 11 и 12 — базовая ставка РФ с 01.01.2026 (22% и расчётная 22/122,
                // ФЗ от 28.11.2025 №425-ФЗ). 4 и 6 оставлены для переходных операций.
                new CredentialField('vat_code', 'Ставка НДС в чеке', 'select', false, null, [
                    ['value' => '1', 'label' => 'Без НДС'],
                    ['value' => '2', 'label' => 'НДС 0%'],
                    ['value' => '3', 'label' => 'НДС 10%'],
                    ['value' => '4', 'label' => 'НДС 20%'],
                    ['value' => '11', 'label' => 'НДС 22%'],
                    ['value' => '5', 'label' => 'НДС 10/110'],
                    ['value' => '6', 'label' => 'НДС 20/120'],
                    ['value' => '12', 'label' => 'НДС 22/122'],
                ]),
                new CredentialField('tax_system_code', 'Система налогообложения', 'select', false, null, [
                    ['value' => 'none', 'label' => 'Не указывать'],
                    ['value' => '1', 'label' => 'ОСН'],
                    ['value' => '2', 'label' => 'УСН (доход)'],
                    ['value' => '3', 'label' => 'УСН (доход − расход)'],
                    ['value' => '4', 'label' => 'ЕНВД'],
                    ['value' => '5', 'label' => 'ЕСХН'],
                    ['value' => '6', 'label' => 'Патент'],
                ]),
            ]),
        ];
    }

    public function getTester(string $driverName): IntegrationTester
    {
        return match ($driverName) {
            'yookassa' => new YooKassaTester(),
            default => throw new \InvalidArgumentException("Unknown payment driver: $driverName"),
        };
    }
}
