<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailableBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_available_balance_as_balance_minus_reserved(): void
    {
        $user = \App\Models\User::factory()->create(['bonus_balance' => 500, 'bonus_reserved' => 200]);
        $this->assertSame(300, $user->available_bonus_balance);
    }

    public function test_clamps_available_balance_to_zero_on_overdraft(): void
    {
        $user = \App\Models\User::factory()->create(['bonus_balance' => 100, 'bonus_reserved' => 250]);
        $this->assertSame(0, $user->available_bonus_balance);
    }
}
