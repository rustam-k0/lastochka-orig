<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoyaltyCardEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_loyalty_card_and_lazily_creates_loyalty_id(): void
    {
        // Статусная модель (LASTOCHKI-JAMI-12013): карта пока не подтверждена 1С,
        // номер и QR клиенту не отдаются, но loyalty_id и card_number создаются лениво.
        $user = User::factory()->create(['loyalty_id' => null, 'bonus_balance' => 350]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/loyalty-card');

        $response->assertOk()->assertJsonStructure(['status', 'balance']);
        $response->assertJson(['status' => 'pending', 'balance' => 350]);
        $response->assertJsonMissingPath('cardNumber');
        $response->assertJsonMissingPath('qrPayload');
        $this->assertNotNull($user->fresh()->loyalty_id);
        $this->assertNotNull($user->fresh()->card_number);
        $this->assertSame(350, $response->json('balance'));
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/loyalty-card')->assertUnauthorized();
    }
}
