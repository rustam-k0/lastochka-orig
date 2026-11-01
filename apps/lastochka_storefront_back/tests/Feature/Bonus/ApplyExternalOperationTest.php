<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\BonusTransaction;
use App\Models\User;
use App\Services\Bonus\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyExternalOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_external_offline_earn_idempotently_by_operation_id(): void
    {
        $user = User::factory()->create(['loyalty_id' => 'LTY-Z', 'bonus_balance' => 100]);
        $op = ['operation_id' => '1C-OP-77', 'loyalty_id' => 'LTY-Z', 'type' => 'earn', 'amount' => 60, 'operation_source' => 'offline'];

        $svc = app(BonusService::class);
        $svc->applyExternalOperation($op);
        $svc->applyExternalOperation($op); // duplicate — must be no-op

        $this->assertSame(160, (int) $user->fresh()->bonus_balance);
        $this->assertSame(1, BonusTransaction::where('operation_id', '1C-OP-77')->count());
    }

    public function test_applies_external_offline_spend_with_clamp(): void
    {
        $user = User::factory()->create(['loyalty_id' => 'LTY-Q', 'bonus_balance' => 50]);
        app(BonusService::class)->applyExternalOperation([
            'operation_id' => '1C-OP-78', 'loyalty_id' => 'LTY-Q', 'type' => 'spend', 'amount' => 80, 'operation_source' => 'offline',
        ]);
        $this->assertSame(0, (int) $user->fresh()->bonus_balance); // clamp, not negative
    }
}
