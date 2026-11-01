<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductCostPriceConsumeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_from_event_maps_cost_price(): void
    {
        $transformer = app(ProductEventTransformer::class);

        $attributes = $transformer->fromEvent([
            'id' => 'ext-1',
            'shop_code' => config('rabbitmq-events.shop_code'),
            'source' => '1c',
            'sku' => 'SKU-1',
            'title' => 'Товар',
            'unit_type' => 'piece',
            'cost_price' => 90.50,
        ]);

        $this->assertSame(90.50, $attributes['cost_price']);
    }

    public function test_from_event_without_cost_price_does_not_touch_attribute(): void
    {
        $transformer = app(ProductEventTransformer::class);

        $attributes = $transformer->fromEvent([
            'id' => 'ext-2',
            'shop_code' => config('rabbitmq-events.shop_code'),
            'source' => '1c',
            'sku' => 'SKU-2',
            'title' => 'Товар',
            'unit_type' => 'piece',
        ]);

        $this->assertArrayNotHasKey('cost_price', $attributes);
    }

    public function test_price_updated_event_persists_cost_price(): void
    {
        // Product::factory()->create() тянет за собой Shop::factory() —
        // у Shop нет HasFactory trait, поэтому строим Product вручную
        // (тот же паттерн, что в ProductReadyTimeTest/ProductCategory1cFieldTest).
        $product = new Product();
        $product->shop_id = Shop::firstOrFail()->id;
        $product->title = 'Test cost_price ' . uniqid();
        $product->save();

        $transformer = app(ProductEventTransformer::class);
        $transformer->processPriceUpdatedEvent($product, [
            'shop_code' => config('rabbitmq-events.shop_code'),
            'price' => 150.00,
            'cost_price' => 90.50,
        ]);

        $this->assertSame('90.50', (string) $product->fresh()->cost_price);
    }
}
