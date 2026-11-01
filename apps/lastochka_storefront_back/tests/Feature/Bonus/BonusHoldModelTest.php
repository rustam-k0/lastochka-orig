<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\BonusHold;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BonusHoldModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_held_bonus_hold_linked_to_user_and_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $hold = BonusHold::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'amount' => 120,
            'status' => BonusHold::STATUS_HELD,
            'created_at' => now(),
        ]);

        $this->assertSame('held', $hold->fresh()->status);
        $this->assertSame(120, $hold->amount);
    }
}
