<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoyaltyCardStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_lazy_creates_card_and_returns_pending(): void
    {
        $user = User::factory()->create(['phone' => '+79990001122']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/loyalty-card');

        $response->assertOk()->assertJson(['status' => 'pending']);
        $response->assertJsonMissingPath('cardNumber');
        $this->assertNotNull($user->refresh()->card_number);
        $this->assertSame(1, EventOutbox::where('routing_key', 'loyalty_card.created')->count());
    }

    public function test_pending_card_hides_number_and_qr(): void
    {
        $user = User::factory()->create([
            'phone' => '+79990001122',
            'card_number' => '07123456',
            'card_created_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/loyalty-card');

        $response->assertOk()
            ->assertJson(['status' => 'pending'])
            ->assertJsonMissingPath('cardNumber')
            ->assertJsonMissingPath('qrPayload');
    }

    public function test_active_card_returns_number_and_qr(): void
    {
        $user = User::factory()->create([
            'phone' => '+79990001122',
            'bonus_balance' => 250,
            'card_number' => '07123456',
            'card_created_at' => now(),
            'card_confirmed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/loyalty-card');

        $response->assertOk()->assertJson([
            'status' => 'active',
            'cardNumber' => '07123456',
            'qrPayload' => '07123456',
            'balance' => 250,
        ]);
    }

    public function test_otp_registration_creates_card(): void
    {
        $phone = '+79990004455';

        $loginResponse = $this->postJson('/api/v1/auth/phone-login', ['phone' => $phone])->assertOk();
        $code = $loginResponse->json('code');

        $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $code,
        ])->assertOk();

        $user = User::where('phone', $phone)->first();
        $this->assertNotNull($user->card_number);
        $this->assertNull($user->card_confirmed_at);
    }

    public function test_repeat_otp_login_of_existing_user_does_not_create_card(): void
    {
        $phone = '+79990007788';
        $existing = User::factory()->create(['phone' => $phone, 'card_number' => null]);

        $loginResponse = $this->postJson('/api/v1/auth/phone-login', ['phone' => $phone])->assertOk();
        $code = $loginResponse->json('code');

        $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $code,
        ])->assertOk();

        // Карта создаётся только при регистрации нового юзера; повторный логин
        // существующего НЕ удалённого юзера карту не создаёт (LASTOCHKI-JAMI-12013).
        $this->assertNull($existing->refresh()->card_number);
        $this->assertSame(0, EventOutbox::where('routing_key', 'loyalty_card.created')->count());
    }
}
