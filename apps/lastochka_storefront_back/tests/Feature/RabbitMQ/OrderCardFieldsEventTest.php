<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCardFieldsEventTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $userAttrs): Order
    {
        $user = User::factory()->create($userAttrs + ['phone' => '+79990001122']);
        $store = Store::factory()->create();

        return Order::factory()->create(['user_id' => $user->id, 'store_id' => $store->id]);
    }

    public function test_to_event_has_card_true_with_confirmed_card(): void
    {
        $order = $this->makeOrder([
            'card_number' => '07123456',
            'card_created_at' => now(),
            'card_confirmed_at' => now(),
        ]);

        $payload = (new OrderEventTransformer())->toEvent($order);

        $this->assertTrue($payload['has_card']);
        $this->assertSame('07123456', $payload['card_number']);
    }

    public function test_to_event_has_card_false_with_pending_card(): void
    {
        $order = $this->makeOrder([
            'card_number' => '07123456',
            'card_created_at' => now(),
        ]);

        $payload = (new OrderEventTransformer())->toEvent($order);

        $this->assertFalse($payload['has_card']);
        $this->assertNull($payload['card_number']);
    }

    public function test_custom_order_created_carries_card_fields(): void
    {
        $order = $this->makeOrder([
            'card_number' => '07123456',
            'card_created_at' => now(),
            'card_confirmed_at' => now(),
        ]);

        $payload = (new OrderEventTransformer())->toCustomEvent($order, 'order_created', []);

        $this->assertTrue($payload['has_card']);
        $this->assertSame('07123456', $payload['card_number']);
    }

    public function test_custom_order_supplemented_carries_card_fields(): void
    {
        $order = $this->makeOrder([
            'card_number' => '07123456',
            'card_created_at' => now(),
            'card_confirmed_at' => now(),
        ]);

        $payload = (new OrderEventTransformer())->toCustomEvent($order, 'order_supplemented', []);

        $this->assertTrue($payload['has_card']);
        $this->assertSame('07123456', $payload['card_number']);
    }
}
