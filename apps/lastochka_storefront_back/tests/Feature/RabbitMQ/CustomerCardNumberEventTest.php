<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\User;
use App\RabbitMQ\Transformers\CustomerEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCardNumberEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_card_number_in_payload(): void
    {
        $user = User::factory()->create([
            'phone' => '+79990001122',
            'card_number' => '07123456',
            'card_created_at' => now(),
            'card_confirmed_at' => now(),
        ]);

        $payload = (new CustomerEventTransformer())->toEvent($user);

        $this->assertSame('07123456', $payload['card_number']);
    }

    public function test_pending_card_number_is_null_in_payload(): void
    {
        $user = User::factory()->create([
            'phone' => '+79990001122',
            'card_number' => '07123456',
            'card_created_at' => now(),
        ]);

        $payload = (new CustomerEventTransformer())->toEvent($user);

        $this->assertNull($payload['card_number']);
    }
}
