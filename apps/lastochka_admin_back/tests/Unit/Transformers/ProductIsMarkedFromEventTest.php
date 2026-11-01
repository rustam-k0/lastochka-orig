<?php

namespace Tests\Unit\Transformers;

use App\Transformers\ProductEventTransformer;
use Tests\TestCase;

/**
 * Признак маркируемого товара (Честный знак, MID-266) из события 1С.
 *
 * 1С шлёт items[].is_marked в product.batch_updated. Ключ переносится только
 * когда присутствует: партиальные события (retailbridge, цены/остатки) не
 * должны сбрасывать признак, выставленный ранее 1С или чекбоксом в админке.
 *
 * Тест не трогает БД: payload без category_1c_external_id.
 */
class ProductIsMarkedFromEventTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'a5f1c0de-0000-4000-8000-000000000266',
            'id' => 'guid-marked-1',
            'shop_code' => 'mendev',
            'source' => '1c',
            'sku' => 'ART-266',
            'title' => 'Водка 0.5',
            'unit_type' => 'piece',
        ], $overrides);
    }

    public function test_true_is_transferred(): void
    {
        $attributes = (new ProductEventTransformer())->fromEvent($this->payload(['is_marked' => true]));

        $this->assertArrayHasKey('is_marked', $attributes);
        $this->assertTrue($attributes['is_marked']);
    }

    public function test_false_is_transferred(): void
    {
        $attributes = (new ProductEventTransformer())->fromEvent($this->payload(['is_marked' => false]));

        $this->assertArrayHasKey('is_marked', $attributes);
        $this->assertFalse($attributes['is_marked']);
    }

    public function test_null_means_not_marked(): void
    {
        $attributes = (new ProductEventTransformer())->fromEvent($this->payload(['is_marked' => null]));

        $this->assertArrayHasKey('is_marked', $attributes);
        $this->assertFalse($attributes['is_marked']);
    }

    public function test_missing_key_does_not_touch_attribute(): void
    {
        $attributes = (new ProductEventTransformer())->fromEvent($this->payload());

        $this->assertArrayNotHasKey('is_marked', $attributes);
    }
}
