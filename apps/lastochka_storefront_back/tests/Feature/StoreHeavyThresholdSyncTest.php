<?php

namespace Tests\Feature;

use App\RabbitMQ\Transformers\StoreEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreHeavyThresholdSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_event_maps_heavy_order_threshold(): void
    {
        $attributes = app(StoreEventTransformer::class)->fromEvent([
            'id' => '1',
            'shop_code' => 'mendev',
            'source' => 'admin',
            'title' => 'Магазин',
            'active' => true,
            'heavy_order_threshold' => 30000,
        ]);

        $this->assertSame(30000, $attributes['heavy_order_threshold']);
    }

    public function test_from_event_null_threshold_when_absent(): void
    {
        $attributes = app(StoreEventTransformer::class)->fromEvent([
            'id' => '1',
            'shop_code' => 'mendev',
            'source' => 'admin',
            'title' => 'Магазин',
            'active' => true,
        ]);

        $this->assertNull($attributes['heavy_order_threshold']);
    }
}
