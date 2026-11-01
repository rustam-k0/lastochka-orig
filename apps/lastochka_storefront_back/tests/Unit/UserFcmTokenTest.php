<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserFcmToken;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserFcmTokenTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Тесты метода getActiveTokensForUser() ====================

    public function test_get_active_tokens_for_user_returns_only_user_tokens(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token1 = UserFcmToken::factory()->create(['user_id' => $user1->id]);
        $token2 = UserFcmToken::factory()->create(['user_id' => $user1->id]);
        UserFcmToken::factory()->create(['user_id' => $user2->id]); // токен другого пользователя

        $tokens = UserFcmToken::getActiveTokensForUser($user1->id);

        $this->assertCount(2, $tokens);
        $this->assertTrue($tokens->contains($token1->token));
        $this->assertTrue($tokens->contains($token2->token));
    }

    public function test_get_active_tokens_for_user_returns_empty_collection_when_no_tokens(): void
    {
        $user = User::factory()->create();

        $tokens = UserFcmToken::getActiveTokensForUser($user->id);

        $this->assertCount(0, $tokens);
        // getActiveTokensForUser()/getAllActiveTokens() делают pluck('token'),
        // то есть отдают строки токенов, а не модели.
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $tokens);
    }

    // ==================== Тесты метода getAllActiveTokens() ====================

    public function test_get_all_active_tokens_returns_all_tokens(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token1 = UserFcmToken::factory()->create(['user_id' => $user1->id]);
        $token2 = UserFcmToken::factory()->create(['user_id' => $user2->id]);

        $tokens = UserFcmToken::getAllActiveTokens();

        $this->assertCount(2, $tokens);
        $this->assertTrue($tokens->contains($token1->token));
        $this->assertTrue($tokens->contains($token2->token));
    }

    public function test_get_all_active_tokens_returns_empty_collection_when_no_tokens(): void
    {
        $tokens = UserFcmToken::getAllActiveTokens();

        $this->assertCount(0, $tokens);
        // getActiveTokensForUser()/getAllActiveTokens() делают pluck('token'),
        // то есть отдают строки токенов, а не модели.
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $tokens);
    }

    // ==================== Тесты метода updateOrCreateToken() ====================

    public function test_update_or_create_token_creates_new_token(): void
    {
        $user = User::factory()->create();

        $token = UserFcmToken::updateOrCreateToken(
            userId: $user->id,
            token: 'new_fcm_token',
            deviceType: 'ios',
            deviceName: 'iPhone 15'
        );

        $this->assertInstanceOf(UserFcmToken::class, $token);
        $this->assertEquals($user->id, $token->user_id);
        $this->assertEquals('new_fcm_token', $token->token);
        $this->assertEquals('ios', $token->device_type);
        $this->assertEquals('iPhone 15', $token->device_name);

        $this->assertDatabaseCount('user_fcm_tokens', 1);
    }

    public function test_update_or_create_token_updates_existing_token(): void
    {
        $user = User::factory()->create();
        $existingToken = UserFcmToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'existing_token',
            'device_type' => 'android',
            'device_name' => 'Samsung',
        ]);

        $updatedToken = UserFcmToken::updateOrCreateToken(
            userId: $user->id,
            token: 'existing_token',
            deviceType: 'ios',
            deviceName: 'iPhone 15'
        );

        $this->assertEquals($existingToken->id, $updatedToken->id); // тот же ID
        $this->assertEquals('existing_token', $updatedToken->token);
        $this->assertEquals('ios', $updatedToken->device_type);
        $this->assertEquals('iPhone 15', $updatedToken->device_name);

        // Проверяем, что не создался новый токен
        $this->assertDatabaseCount('user_fcm_tokens', 1);
    }

    public function test_update_or_create_token_handles_minimal_data(): void
    {
        $user = User::factory()->create();

        $token = UserFcmToken::updateOrCreateToken(
            userId: $user->id,
            token: 'minimal_token',
            deviceType: null,
            deviceName: null
        );

        $this->assertEquals($user->id, $token->user_id);
        $this->assertEquals('minimal_token', $token->token);
        $this->assertNull($token->device_type);
        $this->assertNull($token->device_name);
    }

    public function test_update_or_create_token_same_token_different_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token1 = UserFcmToken::updateOrCreateToken(
            userId: $user1->id,
            token: 'same_token',
            deviceType: 'ios',
            deviceName: 'iPhone User1'
        );

        $token2 = UserFcmToken::updateOrCreateToken(
            userId: $user2->id,
            token: 'same_token',
            deviceType: 'android',
            deviceName: 'Samsung User2'
        );

        // Токен FCM принадлежит устройству, а не пользователю: ключ поиска в
        // updateOrCreateToken — только сам token, поэтому вход второго
        // пользователя на том же устройстве переносит запись на него,
        // а не плодит вторую.
        $this->assertEquals($token1->id, $token2->id);
        $this->assertEquals($user2->id, $token2->fresh()->user_id);
        $this->assertEquals('same_token', $token1->token);
        $this->assertEquals('same_token', $token2->token);
        $this->assertEquals('Samsung User2', $token2->device_name);

        $this->assertDatabaseCount('user_fcm_tokens', 1);
    }

    // ==================== Тесты связей модели ====================

    public function test_user_relationship(): void
    {
        $user = User::factory()->create();
        $token = UserFcmToken::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $token->user);
        $this->assertEquals($user->id, $token->user->id);
    }

    public function test_user_has_many_fcm_tokens(): void
    {
        $user = User::factory()->create();
        $token1 = UserFcmToken::factory()->create(['user_id' => $user->id]);
        $token2 = UserFcmToken::factory()->create(['user_id' => $user->id]);

        $userTokens = $user->fcmTokens;

        $this->assertCount(2, $userTokens);
        $this->assertTrue($userTokens->contains($token1));
        $this->assertTrue($userTokens->contains($token2));
    }

    // ==================== Тесты кастов модели ====================

    public function test_casts_work_correctly(): void
    {
        $user = User::factory()->create();
        $token = UserFcmToken::factory()->create([
            'user_id' => $user->id,
            'last_sent_at' => '2024-01-01 12:00:00',
        ]);

        $freshToken = UserFcmToken::find($token->id);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $freshToken->last_sent_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $freshToken->created_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $freshToken->updated_at);
    }

    // ==================== Тесты фабрики ====================

    public function test_factory_creates_valid_token(): void
    {
        $token = UserFcmToken::factory()->create();

        $this->assertNotNull($token->user_id);
        $this->assertNotNull($token->token);
        $this->assertStringStartsWith('fcm_token_', $token->token);
        $this->assertContains($token->device_type, ['ios', 'android']);
    }

    public function test_factory_ios_state(): void
    {
        $token = UserFcmToken::factory()->ios()->create();

        $this->assertEquals('ios', $token->device_type);
        $this->assertTrue(Str::contains($token->device_name, ['iPhone', 'iPad']));
    }

    public function test_factory_android_state(): void
    {
        $token = UserFcmToken::factory()->android()->create();

        $this->assertEquals('android', $token->device_type);
        $this->assertTrue(Str::contains($token->device_name, ['Samsung', 'Xiaomi', 'Google', 'OnePlus']));
    }
}