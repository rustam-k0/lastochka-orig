<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * is_new / is_hurry_up в payload product-событий: toEvent отдаёт всегда,
 * fromEvent пишет только при наличии ключа (частичные события 1С не
 * должны сбрасывать галочки, которые ведёт админ).
 */
class ProductEventTransformerNewHurryUpTest extends TestCase
{
    use DatabaseTransactions;

    private function makeProduct(array $attrs = []): Product
    {
        $shop = Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        return Product::create(array_merge([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
        ], $attrs));
    }

    private function baseEvent(array $extra = []): array
    {
        return array_merge([
            'event_id' => 'test-event-id',
            'id' => 'ext-1',
            'shop_code' => 'aladdinmarketplace',
            'source' => 'retailbridge',
            'sku' => '1C-99',
            'title' => 'Тестовый товар',
            'price' => 100,
            'unit_type' => 'piece',
        ], $extra);
    }

    public function test_to_event_includes_flags(): void
    {
        $product = $this->makeProduct(['is_new' => true, 'is_hurry_up' => false]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh());

        $this->assertTrue($payload['is_new']);
        $this->assertFalse($payload['is_hurry_up']);
    }

    public function test_to_event_flags_default_to_false(): void
    {
        $payload = app(ProductEventTransformer::class)->toEvent($this->makeProduct()->fresh());

        $this->assertArrayHasKey('is_new', $payload);
        $this->assertArrayHasKey('is_hurry_up', $payload);
        $this->assertFalse($payload['is_new']);
        $this->assertFalse($payload['is_hurry_up']);
    }

    public function test_from_event_maps_flags_when_present(): void
    {
        $attrs = app(ProductEventTransformer::class)->fromEvent($this->baseEvent([
            'is_new' => true,
            'is_hurry_up' => 0,
        ]));

        $this->assertTrue($attrs['is_new']);
        $this->assertFalse($attrs['is_hurry_up']);
    }

    public function test_from_event_omits_flags_when_absent(): void
    {
        $attrs = app(ProductEventTransformer::class)->fromEvent($this->baseEvent());

        $this->assertArrayNotHasKey('is_new', $attrs, 'частичное событие без ключа не должно сбрасывать «Новинку»');
        $this->assertArrayNotHasKey('is_hurry_up', $attrs, 'частичное событие без ключа не должно сбрасывать «Успей купить»');
    }
}
