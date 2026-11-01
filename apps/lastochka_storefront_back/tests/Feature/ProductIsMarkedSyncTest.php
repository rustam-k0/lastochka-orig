<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Признак маркируемого товара (Честный знак, MID-266) на витрине: приходит
 * из курьерки в product.updated, переносится только при наличии ключа
 * (партиальные события не сбрасывают), отдаётся в API как isMarked.
 */
final class ProductIsMarkedSyncTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(Store $store): array
    {
        return [
            'shop_code' => $store->code,
            'source' => $store->source ?? 'admin',
            'sku' => 'SKU-266',
            'title' => 'Водка 0.5',
            'price' => 500.0,
        ];
    }

    public function test_from_event_sets_is_marked_when_key_present(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent(array_merge(
            $this->basePayload($store),
            ['is_marked' => true],
        ));

        $this->assertTrue($attributes['is_marked']);
    }

    public function test_from_event_false_resets_is_marked(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent(array_merge(
            $this->basePayload($store),
            ['is_marked' => false],
        ));

        $this->assertArrayHasKey('is_marked', $attributes);
        $this->assertFalse($attributes['is_marked']);
    }

    public function test_from_event_without_key_does_not_touch_is_marked(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent($this->basePayload($store));

        $this->assertArrayNotHasKey('is_marked', $attributes);
    }

    public function test_is_marked_is_stored_and_exposed_in_resource(): void
    {
        $store = Store::factory()->create();

        $product = Product::create([
            'store_id' => $store->id,
            'source' => 'admin',
            'sku' => 'SKU-266',
            'title' => 'Водка 0.5',
            'is_marked' => true,
        ]);

        $this->assertTrue($product->fresh()->is_marked);

        $payload = (new ProductResource($product->fresh()))->toArray(Request::create('/api/v1/products/1'));

        $this->assertTrue($payload['isMarked']);
    }
}
