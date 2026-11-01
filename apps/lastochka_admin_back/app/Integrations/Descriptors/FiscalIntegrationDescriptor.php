<?php

namespace App\Integrations\Descriptors;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\CredentialField;
use App\Integrations\DriverDescriptor;
use App\Integrations\IntegrationTypeDescriptor;
use App\Integrations\Testers\AtolTester;
use App\Integrations\Testers\YooKassaFiscalTester;

class FiscalIntegrationDescriptor extends IntegrationTypeDescriptor
{
    public function type(): string
    {
        return 'fiscal';
    }

    public function label(): string
    {
        return 'Фискализация';
    }

    public function drivers(): array
    {
        return [
            new DriverDescriptor('atol', 'АТОЛ Онлайн', [
                new CredentialField('mode', 'Режим работы', 'select', true, null, [
                    ['value' => 'test', 'label' => 'Тестовый'],
                    ['value' => 'live', 'label' => 'Боевой'],
                ]),
                new CredentialField('ffd_version', 'Версия ФФД', 'select', false, null, [
                    ['value' => '1.05', 'label' => 'ФФД 1.05 (APIv4, по умолчанию)'],
                    ['value' => '1.2', 'label' => 'ФФД 1.2 (APIv5)'],
                ]),
                new CredentialField('login', 'Логин API', 'text'),
                new CredentialField('password', 'Пароль API', 'password'),
                new CredentialField('group_code', 'Код группы касс', 'text'),
                new CredentialField('inn', 'ИНН продавца', 'text'),
                new CredentialField('payment_address', 'Адрес расчётов', 'text'),
                new CredentialField('company_email', 'Email кассы (для чека)', 'text'),
                new CredentialField('sno', 'Система налогообложения', 'select', false, null, [
                    ['value' => 'osn', 'label' => 'ОСН'],
                    ['value' => 'usn_income', 'label' => 'УСН доход'],
                    ['value' => 'usn_income_outcome', 'label' => 'УСН доход-расход'],
                    ['value' => 'envd', 'label' => 'ЕНВД'],
                    ['value' => 'esn', 'label' => 'ЕСХН'],
                    ['value' => 'patent', 'label' => 'Патент'],
                ]),
                // vat22/vat122 — базовая ставка РФ с 01.01.2026 (ФЗ от 28.11.2025
                // №425-ФЗ), приём проверен на тестовом стенде АТОЛ (ФФД 1.05, APIv4).
                // Ставка применяется к позициям без собственного НДС и к доставке.
                new CredentialField('vat', 'Ставка НДС по умолчанию', 'select', false, null, [
                    ['value' => 'none', 'label' => 'Без НДС'],
                    ['value' => 'vat0', 'label' => 'НДС 0%'],
                    ['value' => 'vat10', 'label' => 'НДС 10%'],
                    ['value' => 'vat20', 'label' => 'НДС 20%'],
                    ['value' => 'vat22', 'label' => 'НДС 22%'],
                    ['value' => 'vat110', 'label' => 'НДС 10/110'],
                    ['value' => 'vat120', 'label' => 'НДС 20/120'],
                    ['value' => 'vat122', 'label' => 'НДС 22/122'],
                ]),
                new CredentialField('payment_object', 'Признак предмета расчёта', 'select', false, null, [
                    ['value' => 'commodity', 'label' => 'Товар'],
                    ['value' => 'service', 'label' => 'Услуга'],
                ]),
                new CredentialField('seller_name', 'Название продавца (для чека)', 'text', false),
            ]),
            new DriverDescriptor('yookassa', 'ЮKassa (касса ЮKassa)'),
        ];
    }

    public function getTester(string $driverName): IntegrationTester
    {
        return match ($driverName) {
            'atol' => new AtolTester(),
            'yookassa' => new YooKassaFiscalTester(),
            default => throw new \InvalidArgumentException("Unknown fiscal driver: $driverName"),
        };
    }
}
