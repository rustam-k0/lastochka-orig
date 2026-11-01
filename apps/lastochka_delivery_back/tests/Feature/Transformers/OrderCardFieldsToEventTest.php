<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderCardFieldsToEventTest extends TestCase
{
    use DatabaseTransactions;

    private function transformer(): OrderEventTransformer
    {
        return app(OrderEventTransformer::class);
    }

    private function makeOrder(array $attrs): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        // forceFill: uuid не в fillable delivery-модели, а NOT NULL в схеме
        $order = new Order();
        $order->forceFill(array_merge([
            'uuid' => 'card-ev-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => 1,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ], $attrs))->save();

        return $order;
    }

    /** @test */
    public function to_event_carries_card_and_bonus_fields()
    {
        $order = $this->makeOrder([
            'has_card' => true,
            'card_number' => '07123456',
            'bonus_earned' => 42,
            'bonus_spent' => 120,
        ]);

        $event = $this->transformer()->toEvent($order->fresh());

        $this->assertTrue($event['has_card']);
        $this->assertSame('07123456', $event['card_number']);
        $this->assertSame(42, $event['bonus_earned']);
        $this->assertSame(120, $event['bonus_spent']);
    }

    /** @test */
    public function to_event_hides_card_number_when_has_card_false()
    {
        $order = $this->makeOrder([
            'has_card' => false,
            'card_number' => null,
        ]);

        $event = $this->transformer()->toEvent($order->fresh());

        $this->assertFalse($event['has_card']);
        $this->assertNull($event['card_number']);
    }
}
