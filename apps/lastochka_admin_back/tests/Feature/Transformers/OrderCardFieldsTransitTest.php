<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderCardFieldsTransitTest extends TestCase
{
    use DatabaseTransactions;

    private function transformer(): OrderEventTransformer
    {
        return app(OrderEventTransformer::class);
    }

    /** @test */
    public function from_event_maps_has_card_and_card_number()
    {
        $attributes = $this->transformer()->fromEvent([
            'id' => 'uuid-card-1',
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'cash',
            'shipping_method' => 'courier',
            'grand_total' => 100,
            'has_card' => true,
            'card_number' => '07123456',
        ]);

        $this->assertTrue($attributes['has_card']);
        $this->assertSame('07123456', $attributes['card_number']);
    }

    /** @test */
    public function from_event_does_not_write_card_number_when_has_card_false()
    {
        $attributes = $this->transformer()->fromEvent([
            'id' => 'uuid-card-2',
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'cash',
            'shipping_method' => 'courier',
            'grand_total' => 100,
            'has_card' => false,
            // card_number пришёл бы мусором от клиента без подтверждённой карты — игнорируем
            'card_number' => '99999999',
        ]);

        $this->assertFalse($attributes['has_card']);
        $this->assertNull($attributes['card_number']);
    }

    /** @test */
    public function from_event_skips_card_fields_when_absent()
    {
        $attributes = $this->transformer()->fromEvent([
            'id' => 'uuid-card-3',
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'cash',
            'shipping_method' => 'courier',
            'grand_total' => 100,
        ]);

        $this->assertArrayNotHasKey('has_card', $attributes);
        $this->assertArrayNotHasKey('card_number', $attributes);
    }

    /** @test */
    public function to_event_retransmits_has_card_card_number_and_bonus_earned()
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        $order = Order::create([
            'uuid' => 'card-ev-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => 1,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'has_card' => true,
            'card_number' => '07123456',
            'bonus_earned' => 42,
        ]);

        $event = $this->transformer()->toEvent($order->fresh());

        $this->assertTrue($event['has_card']);
        $this->assertSame('07123456', $event['card_number']);
        $this->assertSame(42, $event['bonus_earned']);
    }

    /** @test */
    public function to_event_hides_card_number_when_has_card_false()
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        $order = Order::create([
            'uuid' => 'card-ev-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => 1,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'has_card' => false,
            'card_number' => null,
        ]);

        $event = $this->transformer()->toEvent($order->fresh());

        $this->assertFalse($event['has_card']);
        $this->assertNull($event['card_number']);
    }
}
