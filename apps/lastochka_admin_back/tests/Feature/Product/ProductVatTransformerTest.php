<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Transformers\ProductEventTransformer;
use Tests\TestCase;

class ProductVatTransformerTest extends TestCase
{
    public function test_from_event_maps_nds_to_vat_code(): void
    {
        $attrs = (new ProductEventTransformer())->fromEvent([
            'id' => 'GUID-1', 'sku' => 'A', 'title' => 'Товар', 'unit_type' => 'piece', 'nds' => 3,
        ]);

        $this->assertArrayHasKey('vat_code', $attrs);
        $this->assertSame(3, $attrs['vat_code']);
    }

    public function test_from_event_null_nds_sets_vat_code_null(): void
    {
        $attrs = (new ProductEventTransformer())->fromEvent([
            'id' => 'GUID-1', 'sku' => 'A', 'title' => 'Товар', 'unit_type' => 'piece', 'nds' => null,
        ]);

        $this->assertArrayHasKey('vat_code', $attrs);
        $this->assertNull($attrs['vat_code']);
    }

    public function test_from_event_absent_nds_does_not_touch_vat_code(): void
    {
        $attrs = (new ProductEventTransformer())->fromEvent([
            'id' => 'GUID-1', 'sku' => 'A', 'title' => 'Товар', 'unit_type' => 'piece',
        ]);

        $this->assertArrayNotHasKey('vat_code', $attrs);
    }
}
