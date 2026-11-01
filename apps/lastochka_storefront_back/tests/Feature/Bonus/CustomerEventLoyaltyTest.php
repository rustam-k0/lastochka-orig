<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerEventLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    public function test_includes_loyalty_id_and_bonus_balance_in_customer_event(): void
    {
        $user = User::factory()->create([
            'loyalty_id' => 'LTY-AA', 'bonus_balance' => 420, 'phone' => '+79990000000',
        ]);
        $payload = app(\App\RabbitMQ\Transformers\CustomerEventTransformer::class)->toEvent($user);
        $this->assertSame('LTY-AA', $payload['loyalty_id']);
        $this->assertSame(420, $payload['bonus_balance']);
    }

    public function test_before_update_preserves_local_loyalty_id_when_inbound_omits_it(): void
    {
        $user = \App\Models\User::factory()->create(['loyalty_id' => 'LTY-LOCAL', 'phone' => '+79990000001']);
        $transformer = app(\App\RabbitMQ\Transformers\CustomerEventTransformer::class);
        $transformed = $transformer->fromEvent(['phone' => '+79990000001']); // omits loyalty_id → fromEvent injects null
        $result = $transformer->beforeUpdate($user, ['event_source' => 'admin', 'phone' => '+79990000001'], $transformed);
        $this->assertArrayNotHasKey('loyalty_id', $result ?? []); // stripped → local preserved
    }

    public function test_before_update_strips_inbound_loyalty_id_value_when_local_set(): void
    {
        $user = \App\Models\User::factory()->create(['loyalty_id' => 'LTY-LOCAL', 'phone' => '+79990000002']);
        $transformer = app(\App\RabbitMQ\Transformers\CustomerEventTransformer::class);
        $transformed = $transformer->fromEvent(['phone' => '+79990000002', 'loyalty_id' => 'LTY-OTHER']);
        $result = $transformer->beforeUpdate($user, ['event_source' => 'admin', 'phone' => '+79990000002'], $transformed);
        $this->assertArrayNotHasKey('loyalty_id', $result ?? []); // foreign loyalty_id stripped → local preserved
    }
}
