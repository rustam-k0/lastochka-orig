<?php

namespace Tests\Unit\Transformers;

use App\Transformers\ProductEventTransformer;
use Tests\TestCase;

/**
 * fromEvent должен переносить deleted_at из события 1С в атрибуты товара.
 *
 * Ключ приходит из alladin_1c_parser: непустое значение означает удаление по
 * признаку DelProd, явный null — восстановление товара, снятое в 1С.
 * Без переноса восстановление до курьерки не доезжает и товар остаётся
 * мягко удалённым навсегда.
 *
 * Тест не трогает БД: payload намеренно не содержит category_1c_external_id —
 * единственного поля, из-за которого fromEvent ходит в базу.
 */
class ProductDeletedAtFromEventTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'a5f1c0de-0000-4000-8000-000000000001',
            'id' => 'guid-delprod-1',
            'shop_code' => 'aladdin',
            'source' => '1c',
            'sku' => 'ART-1',
            'title' => 'Пряники Домашние 5кг',
            'unit_type' => 'piece',
        ], $overrides);
    }

    public function test_deleted_at_is_transferred_when_present(): void
    {
        $transformer = new ProductEventTransformer();

        $attributes = $transformer->fromEvent($this->payload([
            'deleted_at' => '2026-07-31T19:13:09+03:00',
        ]));

        $this->assertArrayHasKey('deleted_at', $attributes);
        $this->assertNotNull($attributes['deleted_at']);
        $this->assertSame('2026-07-31 19:13:09', $attributes['deleted_at']->format('Y-m-d H:i:s'));
    }

    public function test_explicit_null_restores_product(): void
    {
        $transformer = new ProductEventTransformer();

        $attributes = $transformer->fromEvent($this->payload([
            'deleted_at' => null,
        ]));

        $this->assertArrayHasKey('deleted_at', $attributes);
        $this->assertNull($attributes['deleted_at']);
    }

    public function test_absent_key_does_not_touch_deleted_at(): void
    {
        $transformer = new ProductEventTransformer();

        $attributes = $transformer->fromEvent($this->payload());

        $this->assertArrayNotHasKey('deleted_at', $attributes);
    }
}
