<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_defaults_for_fresh_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/users/me/notification-settings')
            ->assertOk()
            ->assertJsonPath('data.ordersEnabled', true)
            ->assertJsonPath('data.promoEnabled', true)
            ->assertJsonPath('data.pushEnabled', true)
            ->assertJsonPath('data.emailEnabled', false);
    }

    public function test_show_returns_persisted_flags(): void
    {
        $user = User::factory()->create([
            'orders_enabled' => true,
            'promo_enabled' => false,
            'push_enabled' => false,
            'email_enabled' => true,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/users/me/notification-settings')
            ->assertOk()
            ->assertJsonPath('data.ordersEnabled', true)
            ->assertJsonPath('data.promoEnabled', false)
            ->assertJsonPath('data.pushEnabled', false)
            ->assertJsonPath('data.emailEnabled', true);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/users/me/notification-settings')->assertUnauthorized();
    }

    public function test_update_modifies_only_provided_fields(): void
    {
        $user = User::factory()->create([
            'orders_enabled' => true,
            'promo_enabled' => true,
            'push_enabled' => true,
            'email_enabled' => false,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/users/me/notification-settings', [
            'promoEnabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.ordersEnabled', true)
            ->assertJsonPath('data.promoEnabled', false)
            ->assertJsonPath('data.pushEnabled', true)
            ->assertJsonPath('data.emailEnabled', false);

        $user->refresh();
        $this->assertTrue($user->orders_enabled);
        $this->assertFalse($user->promo_enabled);
        $this->assertTrue($user->push_enabled);
        $this->assertFalse($user->email_enabled);
    }

    public function test_update_accepts_all_four_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/users/me/notification-settings', [
            'ordersEnabled' => false,
            'promoEnabled' => false,
            'pushEnabled' => false,
            'emailEnabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.ordersEnabled', false)
            ->assertJsonPath('data.promoEnabled', false)
            ->assertJsonPath('data.pushEnabled', false)
            ->assertJsonPath('data.emailEnabled', true);

        $this->assertTrue($user->refresh()->email_enabled);
    }

    public function test_update_validates_boolean_types(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/users/me/notification-settings', [
            'ordersEnabled' => 'not-a-bool',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ordersEnabled']);
    }

    public function test_update_requires_authentication(): void
    {
        $this->patchJson('/api/v1/users/me/notification-settings', [
            'pushEnabled' => false,
        ])->assertUnauthorized();
    }

    public function test_update_validates_email_enabled_type(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/users/me/notification-settings', [
            'emailEnabled' => 'not-a-bool',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['emailEnabled']);
    }

    public function test_email_enabled_can_be_turned_on_without_address(): void
    {
        $user = User::factory()->empty()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/users/me/notification-settings', [
            'emailEnabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.emailEnabled', true);

        $this->assertTrue($user->refresh()->email_enabled);
    }
}
