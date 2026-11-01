<?php

namespace Tests\Feature\Transformers;

use App\Transformers\ProductEventTransformer;
use Tests\TestCase;

/**
 * Проверяет contract `ProductEventTransformer::fromEvent()`:
 * опциональные поля должны возвращаться ТОЛЬКО если явно присутствуют в payload.
 *
 * Без этого партиал-апдейт (например, `product.updated` от retailbridge,
 * в котором нет ключа `barcodes`) затирает существующее значение поля `barcode` в БД
 * через безусловный `$data['barcodes'][0] ?? null` в fromEvent.
 */
class ProductEventFromEventTest extends TestCase
{
    private ProductEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ProductEventTransformer;
    }

    private function baseEvent(array $overrides = []): array
    {
        return array_merge([
            'event_id'   => 'test-event-id',
            'id'         => 'ext-1',
            'shop_code'  => 'aladdinmarketplace',
            'source'     => 'retailbridge',
            'sku'        => '1C-10982',
            'title'      => 'Кофе холодный Колд Брю',
            'price'      => 150,
            'unit_type'  => 'piece',
        ], $overrides);
    }

    public function test_barcode_absent_when_barcodes_field_missing(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent());

        $this->assertArrayNotHasKey(
            'barcode',
            $attrs,
            'fromEvent() must not include barcode when payload has no barcodes field, иначе Eloquent update затрёт существующий barcode на null'
        );
    }

    public function test_barcode_set_to_first_value_when_barcodes_provided(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'barcodes' => ['9320990330', '9999999999'],
        ]));

        $this->assertArrayHasKey('barcode', $attrs);
        $this->assertSame('9320990330', $attrs['barcode']);
    }

    public function test_barcode_nullable_when_barcodes_explicitly_empty(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'barcodes' => [],
        ]));

        $this->assertArrayHasKey('barcode', $attrs);
        $this->assertNull($attrs['barcode']);
    }

    public function test_description_absent_when_field_missing(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent());

        $this->assertArrayNotHasKey('description', $attrs);
        $this->assertArrayNotHasKey('meta_title', $attrs);
        $this->assertArrayNotHasKey('meta_description', $attrs);
        $this->assertArrayNotHasKey('meta_keywords', $attrs);
    }

    public function test_description_passed_through_when_present(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'description'      => 'full',
            'meta_title'       => 'meta',
            'meta_description' => 'meta-desc',
            'meta_keywords'    => 'k1,k2',
        ]));

        $this->assertSame('full', $attrs['description']);
        $this->assertSame('meta', $attrs['meta_title']);
        $this->assertSame('meta-desc', $attrs['meta_description']);
        $this->assertSame('k1,k2', $attrs['meta_keywords']);
    }

    public function test_weight_absent_when_field_missing(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent());

        $this->assertArrayNotHasKey('weight', $attrs);
    }

    public function test_flags_absent_when_fields_missing(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent());

        $this->assertArrayNotHasKey('is_culinary', $attrs, 'is_culinary должен сохранять существующее значение при партиал-апдейте');
        $this->assertArrayNotHasKey('sugar_free', $attrs);
        $this->assertArrayNotHasKey('on_search_page', $attrs);
        $this->assertArrayNotHasKey('served_warm', $attrs);
    }

    public function test_flags_passed_through_when_present(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'is_culinary'    => true,
            'sugar_free'     => true,
            'on_search_page' => true,
            'served_warm'    => true,
        ]));

        $this->assertTrue($attrs['is_culinary']);
        $this->assertTrue($attrs['sugar_free']);
        $this->assertTrue($attrs['on_search_page']);
        $this->assertTrue($attrs['served_warm']);
    }

    public function test_status_id_absent_when_active_field_missing(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent());

        $this->assertArrayNotHasKey('status_id', $attrs, 'status_id не должен проставляться "на всякий случай" если active не передан');
    }

    public function test_status_id_set_when_active_provided(): void
    {
        $activeEvent = $this->transformer->fromEvent($this->baseEvent(['active' => true]));
        $inactiveEvent = $this->transformer->fromEvent($this->baseEvent(['active' => false]));

        $this->assertArrayHasKey('status_id', $activeEvent);
        $this->assertArrayHasKey('status_id', $inactiveEvent);
        $this->assertNotSame($activeEvent['status_id'], $inactiveEvent['status_id']);
    }

    public function test_required_fields_always_present(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent());

        $this->assertSame('1C-10982', $attrs['articul']);
        $this->assertSame('Кофе холодный Колд Брю', $attrs['title']);
        $this->assertArrayHasKey('unit_type_id', $attrs);
    }

    public function test_code_absent_when_field_missing(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent());

        $this->assertArrayNotHasKey(
            'code',
            $attrs,
            'code должен сохранять существующее значение при партиал-апдейте, если ключа нет в payload'
        );
    }

    public function test_code_passed_through_when_present(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'code' => '00042',
        ]));

        $this->assertArrayHasKey('code', $attrs);
        $this->assertSame('00042', $attrs['code']);
    }

    public function test_code_nullable_when_explicitly_null(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'code' => null,
        ]));

        $this->assertArrayHasKey('code', $attrs);
        $this->assertNull($attrs['code']);
    }

    public function test_plu_absent_when_field_missing(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent());

        $this->assertArrayNotHasKey(
            'plu',
            $attrs,
            'plu должен сохранять существующее значение при партиал-апдейте, если ключа нет в payload'
        );
    }

    public function test_plu_passed_through_when_present(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'plu' => '4126',
        ]));

        $this->assertArrayHasKey('plu', $attrs);
        $this->assertSame('4126', $attrs['plu']);
    }

    public function test_plu_nullable_when_explicitly_null(): void
    {
        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'plu' => null,
        ]));

        $this->assertArrayHasKey('plu', $attrs);
        $this->assertNull($attrs['plu']);
    }
}
