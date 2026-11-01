<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderEventBonusEarnedTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_event_includes_bonus_earned(): void
    {
        $order = Order::factory()->create(['bonus_earned' => 50, 'bonus_spent' => 0]);
        $payload = app(\App\RabbitMQ\Transformers\OrderEventTransformer::class)->toEvent($order->fresh());
        $this->assertArrayHasKey('bonus_earned', $payload);
        $this->assertSame(50, (int) $payload['bonus_earned']);
    }
}
