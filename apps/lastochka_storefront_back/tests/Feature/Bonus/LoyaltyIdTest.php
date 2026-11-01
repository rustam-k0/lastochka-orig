<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_loyalty_id_on_user(): void
    {
        $user = User::factory()->create(['loyalty_id' => 'LTY-ABC123']);
        $this->assertSame('LTY-ABC123', $user->fresh()->loyalty_id);
    }

    public function test_enforces_unique_loyalty_id(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->create(['loyalty_id' => 'LTY-DUP']);
        User::factory()->create(['loyalty_id' => 'LTY-DUP']);
    }
}
