<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Транзит orders.assembly_cost (стоимость сборки, модуль assembly_cost) через события.
 *
 * Поле снапшотится storefront'ом при создании заказа и должно доезжать до
 * delivery_back: пересчёты суммы после сборки используют его как слагаемое,
 * иначе capture в YooKassa списывает меньше захолдированного.
 */
class OrderAssemblyCostTransitTest extends TestCase
{
    use DatabaseTransactions;

    private function transformer(): OrderEventTransformer
    {
        return app(OrderEventTransformer::class);
    }

    /** @test */
    public function from_event_maps_assembly_cost()
    {
        $attributes = $this->transformer()->fromEvent([
            'id' => 'uuid-ac-1',
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => 'courier',
            'grand_total' => 1995,
            'assembly_cost' => 150,
        ]);

        $this->assertSame(150, $attributes['assembly_cost']);
    }

    /** @test */
    public function from_event_skips_assembly_cost_when_absent()
    {
        $attributes = $this->transformer()->fromEvent([
            'id' => 'uuid-ac-2',
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => 'courier',
            'grand_total' => 1995,
        ]);

        $this->assertArrayNotHasKey('assembly_cost', $attributes);
    }

    /** @test */
    public function to_event_carries_assembly_cost()
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        $order = new Order();
        $order->forceFill([
            'uuid' => 'ac-ev-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => 1,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 1995,
            'assembly_cost' => 150,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ])->save();

        $event = $this->transformer()->toEvent($order->fresh());

        $this->assertSame(150, $event['assembly_cost']);
    }
}
