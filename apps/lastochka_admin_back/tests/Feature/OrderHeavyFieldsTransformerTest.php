<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHeavyFieldsTransformerTest extends TestCase
{
    use DatabaseTransactions;

    private function transformer(): OrderEventTransformer
    {
        return app(OrderEventTransformer::class);
    }

    /** @test */
    public function from_event_maps_heavy_fields()
    {
        $attributes = $this->transformer()->fromEvent([
            'id' => 'uuid-test-1',
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'cash',
            'shipping_method' => 'courier',
            'grand_total' => 100,
            'is_heavy' => true,
            'total_weight' => 32000,
            'is_weight_partial' => false,
        ]);

        $this->assertTrue($attributes['is_heavy']);
        $this->assertSame(32000, $attributes['total_weight']);
        $this->assertFalse($attributes['is_weight_partial']);
    }

    /** @test */
    public function from_event_skips_heavy_fields_when_absent()
    {
        $attributes = $this->transformer()->fromEvent([
            'id' => 'uuid-test-2',
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'cash',
            'shipping_method' => 'courier',
            'grand_total' => 100,
        ]);

        $this->assertArrayNotHasKey('is_heavy', $attributes);
        $this->assertArrayNotHasKey('total_weight', $attributes);
        $this->assertArrayNotHasKey('is_weight_partial', $attributes);
    }

    /** @test */
    public function to_event_includes_is_heavy_and_couriers_count()
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        $order = Order::create([
            'uuid' => 'heavy-ev-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => 1,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'is_heavy' => true,
            'couriers_count' => 2,
        ]);

        $event = $this->transformer()->toEvent($order->fresh());

        $this->assertTrue($event['is_heavy']);
        $this->assertSame(2, $event['couriers_count']);
    }
}
