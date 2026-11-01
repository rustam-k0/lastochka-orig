<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDockerSchema;
use Tests\TestCase;

/**
 * Авторизация API аналитики по Sanctum-токенам docker-БД (#11595).
 */
final class StaffAuthTest extends TestCase
{
    use CreatesDockerSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('analytics:ensure-partitions');
        $this->setUpDockerSchema();
    }

    public function test_request_without_token_is_rejected(): void
    {
        $this->getJson('/api/v1/analytics/me')
            ->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_garbage_token_is_rejected(): void
    {
        $this->getJson('/api/v1/analytics/me', ['Authorization' => 'Bearer 999|garbage'])
            ->assertStatus(401);
    }

    public function test_valid_token_authenticates_and_resolves_roles(): void
    {
        [, $bearer] = $this->createStaffWithToken(roles: ['collector'], shopCodes: ['shop-a']);

        $this->getJson('/api/v1/analytics/me', ['Authorization' => "Bearer {$bearer}"])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'picker');
    }

    public function test_courier_defaults_to_courier_role(): void
    {
        [, $bearer] = $this->createStaffWithToken(roles: ['courier']);

        $this->getJson('/api/v1/analytics/me', ['Authorization' => "Bearer {$bearer}"])
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'courier');
    }

    public function test_non_admin_cannot_access_store_endpoints(): void
    {
        [, $bearer] = $this->createStaffWithToken(roles: ['collector']);

        $this->getJson('/api/v1/analytics/employees', ['Authorization' => "Bearer {$bearer}"])
            ->assertStatus(403);

        $this->getJson('/api/v1/analytics/store/summary', ['Authorization' => "Bearer {$bearer}"])
            ->assertStatus(403);
    }

    public function test_invalid_period_is_rejected(): void
    {
        [, $bearer] = $this->createStaffWithToken();

        $this->getJson('/api/v1/analytics/me?period=year', ['Authorization' => "Bearer {$bearer}"])
            ->assertStatus(422);
    }
}
