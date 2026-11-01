<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Descriptors\PaymentIntegrationDescriptor;
use Tests\TestCase;

class PaymentDescriptorTest extends TestCase
{
    private function yookassaSchema(): array
    {
        $driver = (new PaymentIntegrationDescriptor())->getDriver('yookassa');
        $schema = [];
        foreach ($driver->credentialsSchema as $field) {
            $schema[$field->field] = $field;
        }

        return $schema;
    }

    public function test_yookassa_has_send_receipt_checkbox(): void
    {
        $schema = $this->yookassaSchema();

        $this->assertArrayHasKey('send_receipt', $schema);
        $this->assertSame('checkbox', $schema['send_receipt']->type);
        $this->assertFalse($schema['send_receipt']->required);
    }

    public function test_yookassa_vat_code_select_offers_all_rates_including_22(): void
    {
        $schema = $this->yookassaSchema();

        $this->assertArrayHasKey('vat_code', $schema);
        $this->assertSame('select', $schema['vat_code']->type);
        $this->assertFalse($schema['vat_code']->required);

        $values = array_column($schema['vat_code']->options, 'value');
        // 11 и 12 — базовая ставка РФ с 01.01.2026 (22% и расчётная 22/122).
        // Прежние 4 и 6 остаются для переходных операций.
        $this->assertEqualsCanonicalizing(['1', '2', '3', '4', '11', '5', '6', '12'], $values);

        $labels = array_column($schema['vat_code']->options, 'label', 'value');
        $this->assertSame('НДС 22%', $labels['11']);
        $this->assertSame('НДС 22/122', $labels['12']);
    }

    public function test_yookassa_has_tax_system_code_select_with_empty_option(): void
    {
        $schema = $this->yookassaSchema();

        $this->assertArrayHasKey('tax_system_code', $schema);
        $this->assertSame('select', $schema['tax_system_code']->type);
        $this->assertFalse($schema['tax_system_code']->required);
        $this->assertCount(7, $schema['tax_system_code']->options);
        // Первая опция «Не указывать» — sentinel 'none', НЕ пустая строка:
        // пустое value во фронт-рендере select сломалось бы (opt.value || opt → [object Object])
        // и конфликтовало бы с disabled-placeholder value="".
        $this->assertSame('none', $schema['tax_system_code']->options[0]['value']);
        $this->assertSame('Не указывать', $schema['tax_system_code']->options[0]['label']);
    }
}
