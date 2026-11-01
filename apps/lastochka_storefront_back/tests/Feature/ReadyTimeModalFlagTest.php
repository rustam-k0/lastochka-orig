<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ReadyTimeModalFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_returns_false_by_default(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/auth/profile')
            ->assertOk()
            ->assertJsonPath('data.readyTimeModalSuppressed', false);
    }

    public function test_flag_can_be_set_via_profile_update(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/profile', ['readyTimeModalSuppressed' => true])
            ->assertOk()
            ->assertJsonPath('data.readyTimeModalSuppressed', true);

        $this->assertTrue($user->fresh()->ready_time_modal_suppressed);
    }

    public function test_profile_update_without_flag_keeps_value(): void
    {
        $user = User::factory()->create(['ready_time_modal_suppressed' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/profile', ['firstName' => 'Даиб'])->assertOk();

        $this->assertTrue($user->fresh()->ready_time_modal_suppressed);
    }

    public function test_non_boolean_value_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/auth/profile', ['readyTimeModalSuppressed' => 'да'])
            ->assertStatus(422);
    }
}
