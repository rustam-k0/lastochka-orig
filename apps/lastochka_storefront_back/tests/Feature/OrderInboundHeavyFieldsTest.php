<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderInboundHeavyFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_event_maps_is_heavy_and_couriers_count(): void
    {
        $attributes = app(OrderEventTransformer::class)->fromEvent([
            'status' => 'in_delivery',
            'is_heavy' => true,
            'couriers_count' => 2,
        ]);

        $this->assertTrue($attributes['is_heavy']);
        $this->assertSame(2, $attributes['couriers_count']);
    }

    public function test_from_event_skips_heavy_fields_when_absent(): void
    {
        $attributes = app(OrderEventTransformer::class)->fromEvent([
            'status' => 'in_delivery',
        ]);

        $this->assertArrayNotHasKey('is_heavy', $attributes);
        $this->assertArrayNotHasKey('couriers_count', $attributes);
    }
}
