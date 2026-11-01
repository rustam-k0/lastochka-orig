<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductEventTransformerNdsRateTest extends TestCase
{
    use DatabaseTransactions;

    private function event(array $overrides): array
    {
        return array_merge([
            'event_id' => 'test-event-id',
            'id' => 'ext-nds-1',
            'shop_code' => 'default',
            'source' => '1c',
            'event_source' => '1c',
            'sku' => 'NDS-1',
            'title' => 'Товар НДС',
            'price' => 100,
            'unit_type' => 'piece',
        ], $overrides);
    }

    public function test_nds_rate_percent_maps_to_vat_code(): void
    {
        $attrs = app(ProductEventTransformer::class)->fromEvent($this->event(['nds_rate' => '22%']));

        $this->assertSame(11, $attrs['vat_code']);
    }

    public function test_nds_rate_has_priority_over_nds(): void
    {
        $attrs = app(ProductEventTransformer::class)->fromEvent($this->event(['nds' => 3, 'nds_rate' => 'Без НДС']));

        $this->assertSame(1, $attrs['vat_code']);
    }

    public function test_nds_rate_null_clears_vat_code(): void
    {
        $attrs = app(ProductEventTransformer::class)->fromEvent($this->event(['nds_rate' => null]));

        $this->assertArrayHasKey('vat_code', $attrs);
        $this->assertNull($attrs['vat_code']);
    }

    public function test_unrecognized_nds_rate_does_not_touch_vat_code(): void
    {
        $attrs = app(ProductEventTransformer::class)->fromEvent($this->event(['nds' => 3, 'nds_rate' => '5%']));

        $this->assertArrayNotHasKey('vat_code', $attrs, 'нераспознанная ставка не должна затирать НДС товара');
    }

    public function test_legacy_nds_code_still_works_without_nds_rate(): void
    {
        $attrs = app(ProductEventTransformer::class)->fromEvent($this->event(['nds' => 4]));

        $this->assertSame(4, $attrs['vat_code']);
    }
}
