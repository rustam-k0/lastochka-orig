<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PLU весового товара из 1С (реквизит «ПЛУ»): весы печатают его в весовом
 * штрихкоде, поэтому витрина хранит PLU отдельно от кода номенклатуры.
 */
final class ProductPluSyncTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(Store $store): array
    {
        return [
            'shop_code' => $store->code,
            'source' => $store->source ?? 'admin',
            'sku' => '91535',
            'title' => 'Перец Болгарский Красный',
            'price' => 147.0,
        ];
    }

    public function test_from_event_sets_plu_when_key_present(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent(array_merge(
            $this->basePayload($store),
            ['code' => "52\u{A0}026", 'plu' => '00030'],
        ));

        $this->assertSame('52026', $attributes['code']);
        $this->assertSame('00030', $attributes['plu']);
    }

    public function test_from_event_explicit_null_resets_plu(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent(array_merge(
            $this->basePayload($store),
            ['plu' => null],
        ));

        $this->assertArrayHasKey('plu', $attributes);
        $this->assertNull($attributes['plu']);
    }

    public function test_from_event_without_key_does_not_touch_plu(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent($this->basePayload($store));

        $this->assertArrayNotHasKey('plu', $attributes, 'партиальное событие без PLU не должно затирать сохранённое значение');
    }

    public function test_plu_is_fillable_on_product(): void
    {
        $store = Store::factory()->create();

        $product = Product::create([
            'store_id' => $store->id,
            'source' => 'admin',
            'sku' => '91535',
            'title' => 'Перец Болгарский Красный',
            'price' => 147,
            'plu' => '00030',
        ]);

        $this->assertSame('00030', $product->fresh()->plu);
    }
}
