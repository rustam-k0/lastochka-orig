<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyIdGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_unique_loyalty_id(): void
    {
        $a = User::generateLoyaltyId();
        $b = User::generateLoyaltyId();
        $this->assertStringStartsWith('LTY-', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_ensure_loyalty_id_assigns_and_persists_once(): void
    {
        $user = User::factory()->create(['loyalty_id' => null]);
        $first = $user->ensureLoyaltyId();
        $this->assertSame($first, $user->fresh()->loyalty_id);
        $this->assertSame($first, $user->ensureLoyaltyId()); // idempotent
    }
}
