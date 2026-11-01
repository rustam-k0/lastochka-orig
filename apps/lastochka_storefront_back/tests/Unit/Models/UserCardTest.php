<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_active_card_false_without_card(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasActiveCard());
    }

    public function test_has_active_card_false_when_pending(): void
    {
        $user = User::factory()->create([
            'card_number' => '07123456',
            'card_created_at' => now(),
        ]);
        $this->assertFalse($user->hasActiveCard());
    }

    public function test_has_active_card_true_when_confirmed(): void
    {
        $user = User::factory()->create([
            'card_number' => '07123456',
            'card_created_at' => now(),
            'card_confirmed_at' => now(),
        ]);
        $this->assertTrue($user->hasActiveCard());
    }
}
