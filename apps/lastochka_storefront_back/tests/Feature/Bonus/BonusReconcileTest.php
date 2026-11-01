<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\BonusHold;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BonusReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_and_fixes_reserved_drift(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500, 'bonus_reserved' => 999]); // drift
        $order = Order::factory()->create(['user_id' => $user->id]);
        BonusHold::create([
            'user_id' => $user->id, 'order_id' => $order->id, 'amount' => 120, 'status' => 'held', 'created_at' => now(),
        ]);

        $this->artisan('bonus:reconcile --fix')->assertExitCode(0);

        $this->assertSame(120, (int) $user->fresh()->bonus_reserved);
    }
}
