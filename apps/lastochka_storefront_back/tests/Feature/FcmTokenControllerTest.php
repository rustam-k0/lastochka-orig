<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserFcmToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FcmTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ==================== GET /api/v1/fcm-tokens ====================

    public function test_index_returns_user_fcm_tokens(): void
    {
        Sanctum::actingAs($this->user);

        UserFcmToken::factory()->create([
            'user_id' => $this->user->id,
            'token' => 'fcm_token_1',
            'device_type' => 'ios',
            'device_name' => 'iPhone 15 Pro',
            'created_at' => now()->subMinute(),
        ]);

        UserFcmToken::factory()->create([
            'user_id' => $this->user->id,
            'token' => 'fcm_token_2',
            'device_type' => 'android',
            'device_name' => 'Samsung Galaxy S24',
            'created_at' => now(),
        ]);

        UserFcmToken::factory()->create([
            'user_id' => User::factory()->create()->id,
            'token' => 'fcm_token_other',
        ]);

        $response = $this->getJson('/api/v1/fcm-tokens');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.token', 'fcm_token_2')
            ->assertJsonPath('data.0.device_type', 'android')
            ->assertJsonPath('data.1.token', 'fcm_token_1')
            ->assertJsonPath('data.1.device_type', 'ios');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/fcm-tokens')->assertUnauthorized();
    }

    public function test_index_returns_empty_array_when_no_tokens(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/fcm-tokens')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ==================== POST /api/v1/fcm-tokens ====================

    public function test_store_creates_new_fcm_token(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/fcm-tokens', [
            'token' => 'new_fcm_token_12345',
            'deviceType' => 'ios',
            'deviceName' => 'iPhone 15',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.token', 'new_fcm_token_12345')
            ->assertJsonPath('data.device_type', 'ios')
            ->assertJsonPath('data.device_name', 'iPhone 15');

        $this->assertDatabaseHas('user_fcm_tokens', [
            'user_id' => $this->user->id,
            'token' => 'new_fcm_token_12345',
            'device_type' => 'ios',
            'device_name' => 'iPhone 15',
        ]);
    }

    public function test_store_updates_existing_token(): void
    {
        Sanctum::actingAs($this->user);

        $existingToken = UserFcmToken::factory()->create([
            'user_id' => $this->user->id,
            'token' => 'existing_token',
            'device_type' => 'android',
            'device_name' => 'Old Device',
        ]);

        $response = $this->postJson('/api/v1/fcm-tokens', [
            'token' => 'existing_token',
            'deviceType' => 'ios',
            'deviceName' => 'New iPhone',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token', 'existing_token')
            ->assertJsonPath('data.device_type', 'ios')
            ->assertJsonPath('data.device_name', 'New iPhone');

        $this->assertDatabaseCount('user_fcm_tokens', 1);
        $this->assertDatabaseHas('user_fcm_tokens', [
            'id' => $existingToken->id,
            'user_id' => $this->user->id,
            'token' => 'existing_token',
            'device_type' => 'ios',
            'device_name' => 'New iPhone',
        ]);
    }

    public function test_store_validates_required_token(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/fcm-tokens', [
            'deviceType' => 'ios',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }

    public function test_store_validates_device_type_enum(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/fcm-tokens', [
            'token' => 'test_token',
            'deviceType' => 'invalid_type',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deviceType']);
    }

    public function test_store_validates_token_max_length(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/fcm-tokens', [
            'token' => str_repeat('a', 256),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }

    public function test_store_allows_optional_fields(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/fcm-tokens', [
            'token' => 'minimal_token',
        ])
            ->assertCreated()
            ->assertJsonPath('data.token', 'minimal_token')
            ->assertJsonPath('data.device_type', null)
            ->assertJsonPath('data.device_name', null);
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/v1/fcm-tokens', ['token' => 'test_token'])
            ->assertUnauthorized();
    }

    // ==================== DELETE /api/v1/fcm-tokens ====================

    public function test_destroy_removes_user_token(): void
    {
        Sanctum::actingAs($this->user);

        $token = UserFcmToken::factory()->create([
            'user_id' => $this->user->id,
            'token' => 'token_to_delete',
        ]);

        $this->deleteJson('/api/v1/fcm-tokens', ['token' => 'token_to_delete'])
            ->assertNoContent();

        $this->assertDatabaseMissing('user_fcm_tokens', [
            'id' => $token->id,
        ]);
    }

    public function test_destroy_returns_404_for_nonexistent_token(): void
    {
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/fcm-tokens', ['token' => 'nonexistent_token'])
            ->assertNotFound();
    }

    public function test_destroy_only_removes_user_own_tokens(): void
    {
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create();
        $otherToken = UserFcmToken::factory()->create([
            'user_id' => $otherUser->id,
            'token' => 'other_user_token',
        ]);

        $this->deleteJson('/api/v1/fcm-tokens', ['token' => 'other_user_token'])
            ->assertNotFound();

        $this->assertDatabaseHas('user_fcm_tokens', ['id' => $otherToken->id]);
    }

    public function test_destroy_validates_required_token(): void
    {
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/fcm-tokens', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }

    public function test_destroy_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/fcm-tokens', ['token' => 'test_token'])
            ->assertUnauthorized();
    }

    // ==================== PATCH /api/v1/fcm-tokens/settings (removed) ====================

    public function test_settings_endpoint_is_gone(): void
    {
        Sanctum::actingAs($this->user);

        $this->patchJson('/api/v1/fcm-tokens/settings', [
            'token' => 'whatever',
        ])->assertNotFound();
    }
}
