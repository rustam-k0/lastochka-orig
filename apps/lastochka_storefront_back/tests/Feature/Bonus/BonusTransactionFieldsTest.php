<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\BonusTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BonusTransactionFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_operation_id_and_source_and_enforces_uniqueness(): void
    {
        $user = User::factory()->create();
        BonusTransaction::create([
            'user_id' => $user->id, 'type' => 'earn', 'amount' => 10,
            'balance_after' => 10, 'operation_id' => 'OP-1', 'source' => 'offline',
            'created_at' => now(),
        ]);
        $this->assertSame('offline', BonusTransaction::where('operation_id', 'OP-1')->value('source'));

        $this->expectException(QueryException::class);
        BonusTransaction::create([
            'user_id' => $user->id, 'type' => 'earn', 'amount' => 10,
            'balance_after' => 20, 'operation_id' => 'OP-1', 'source' => 'offline', 'created_at' => now(),
        ]);
    }
}
