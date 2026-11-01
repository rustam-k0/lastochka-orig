<?php

namespace Tests\Feature\Integrations;

use App\Integrations\IntegrationRegistry;
use App\Integrations\Testers\AtolTester;
use App\Integrations\Testers\YooKassaFiscalTester;
use Tests\TestCase;

class FiscalDescriptorTest extends TestCase
{
    public function test_fiscal_type_registered_with_two_drivers(): void
    {
        $d = IntegrationRegistry::get('fiscal');
        $this->assertNotNull($d);
        $this->assertSame('fiscal', $d->type());
        $names = array_map(fn ($x) => $x->name, $d->drivers());
        $this->assertEqualsCanonicalizing(['atol', 'yookassa'], $names);
        $this->assertInstanceOf(AtolTester::class, $d->getTester('atol'));
        $this->assertInstanceOf(YooKassaFiscalTester::class, $d->getTester('yookassa'));
    }

    public function test_atol_driver_exposes_required_credential_fields(): void
    {
        $atol = IntegrationRegistry::get('fiscal')->getDriver('atol');
        $fields = array_map(fn ($f) => $f->field, $atol->credentialsSchema);
        foreach (['mode', 'ffd_version', 'login', 'password', 'group_code', 'inn', 'payment_address', 'sno', 'vat', 'company_email'] as $f) {
            $this->assertContains($f, $fields, "missing field {$f}");
        }
    }

    public function test_atol_vat_field_offers_22_percent_rates(): void
    {
        $atol = IntegrationRegistry::get('fiscal')->getDriver('atol');
        $field = collect($atol->credentialsSchema)->firstWhere('field', 'vat');

        $this->assertNotNull($field, 'atol driver must expose vat select');
        // vat22/vat122 — базовая ставка РФ с 01.01.2026 (ФЗ №425-ФЗ).
        $this->assertEqualsCanonicalizing(
            ['none', 'vat0', 'vat10', 'vat20', 'vat22', 'vat110', 'vat120', 'vat122'],
            array_column($field->options, 'value')
        );
    }

    public function test_atol_ffd_version_field_offers_105_and_12(): void
    {
        $atol = IntegrationRegistry::get('fiscal')->getDriver('atol');
        $field = collect($atol->credentialsSchema)->firstWhere('field', 'ffd_version');

        $this->assertNotNull($field, 'atol driver must expose ffd_version select');
        $this->assertSame('select', $field->type);
        $this->assertFalse($field->required, 'ffd_version must be optional (старые конфиги без него = 1.05)');
        $this->assertEqualsCanonicalizing(['1.05', '1.2'], array_column($field->options, 'value'));
    }
}
