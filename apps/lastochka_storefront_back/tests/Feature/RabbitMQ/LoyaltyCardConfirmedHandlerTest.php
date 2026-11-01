<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\User;
use App\RabbitMQ\Handlers\LoyaltyCardConfirmedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyCardConfirmedHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirms_pending_card(): void
    {
        $user = User::factory()->create([
            'card_number' => '07123456',
            'card_created_at' => now()->subMinute(),
        ]);

        $result = (new LoyaltyCardConfirmedHandler())->handle([
            'payload' => ['card_number' => '07123456', 'loyalty_id' => $user->loyalty_id],
        ]);

        $this->assertSame('ack', $result);
        $this->assertNotNull($user->refresh()->card_confirmed_at);
    }

    public function test_repeated_confirm_is_noop(): void
    {
        $confirmedAt = now()->subHour()->startOfSecond();
        $user = User::factory()->create([
            'card_number' => '07123456',
            'card_created_at' => now()->subDay(),
            'card_confirmed_at' => $confirmedAt,
        ]);

        (new LoyaltyCardConfirmedHandler())->handle([
            'payload' => ['card_number' => '07123456'],
        ]);

        $this->assertSame(
            $confirmedAt->format('Y-m-d H:i:s'),
            $user->refresh()->card_confirmed_at->format('Y-m-d H:i:s')
        );
    }

    public function test_unknown_card_number_acks_without_error(): void
    {
        $result = (new LoyaltyCardConfirmedHandler())->handle([
            'payload' => ['card_number' => '07999999'],
        ]);

        $this->assertSame('ack', $result);
    }

    public function test_confirms_pending_card_for_soft_deleted_user(): void
    {
        $user = User::factory()->create([
            'card_number' => '07123456',
            'card_created_at' => now()->subMinute(),
        ]);
        $user->delete();

        $result = (new LoyaltyCardConfirmedHandler())->handle([
            'payload' => ['card_number' => '07123456', 'loyalty_id' => $user->loyalty_id],
        ]);

        $this->assertSame('ack', $result);
        $this->assertNotNull($user->refresh()->card_confirmed_at);
    }
}
