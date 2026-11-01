<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductReadyTimeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(Store $store): array
    {
        return [
            'shop_code' => $store->code,
            'source' => $store->source ?? 'admin',
            'sku' => 'SKU-1',
            'title' => 'Сырники',
            'price' => 100.0,
        ];
    }

    public function test_from_event_sets_ready_fixed_time_when_key_present(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent(array_merge(
            $this->basePayload($store),
            ['ready_fixed_time' => '12:00'],
        ));

        $this->assertSame('12:00', $attributes['ready_fixed_time']);
    }

    public function test_from_event_explicit_null_resets_ready_fixed_time(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent(array_merge(
            $this->basePayload($store),
            ['ready_fixed_time' => null],
        ));

        $this->assertArrayHasKey('ready_fixed_time', $attributes);
        $this->assertNull($attributes['ready_fixed_time']);
    }

    public function test_from_event_without_key_does_not_touch_ready_fixed_time(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent($this->basePayload($store));

        $this->assertArrayNotHasKey('ready_fixed_time', $attributes);
    }

    public function test_ready_fixed_time_is_fillable_on_product(): void
    {
        $store = Store::factory()->create();

        $product = Product::create([
            'store_id' => $store->id,
            'source' => 'admin',
            // sku обязателен на уровне схемы (NOT NULL, без default) — не связано
            // с ready_time-полями, минимальная адаптация setup под требования БД.
            'sku' => 'SKU-1',
            'title' => 'Сырники',
            'price' => 100,
            'ready_fixed_time' => '12:00:00',
        ]);

        $this->assertSame('12:00:00', (string) $product->fresh()->ready_fixed_time);
    }
}
