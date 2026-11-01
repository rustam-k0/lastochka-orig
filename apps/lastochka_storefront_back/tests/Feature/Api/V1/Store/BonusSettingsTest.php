<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Store;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesB2bModule;
use Tests\TestCase;

final class BonusSettingsTest extends TestCase
{
    use RefreshDatabase;
    use TogglesB2bModule;

    protected function tearDown(): void
    {
        $this->invalidateB2bModuleCache();
        parent::tearDown();
    }

    public function test_returns_bonus_settings_for_store_with_bonuses_enabled(): void
    {
        $store = Store::factory()->create([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 5,
            'bonus_spend_percent' => 30,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/bonus-settings");

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    'isEnabled' => true,
                    'earnPercent' => 5,
                    'spendPercent' => 30,
                ],
            ]);
    }

    public function test_returns_zero_percents_when_bonuses_disabled(): void
    {
        $store = Store::factory()->create([
            'is_bonus_enabled' => false,
            'bonus_earn_percent' => 5,
            'bonus_spend_percent' => 30,
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/bonus-settings");

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    'isEnabled' => false,
                    'earnPercent' => 0,
                    'spendPercent' => 0,
                ],
            ]);
    }

    public function test_returns_404_for_missing_store(): void
    {
        $this->getJson('/api/v1/stores/999999/bonus-settings')->assertNotFound();
    }

    public function test_returns_404_for_inactive_store(): void
    {
        $store = Store::factory()->inactive()->create([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 5,
            'bonus_spend_percent' => 30,
        ]);

        $this->getJson("/api/v1/stores/{$store->id}/bonus-settings")->assertNotFound();
    }

    public function test_guest_does_not_see_b2b_store_when_module_enabled(): void
    {
        $this->setB2bModule(true);

        $store = Store::factory()->b2b()->create([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 5,
            'bonus_spend_percent' => 30,
        ]);

        $this->getJson("/api/v1/stores/{$store->id}/bonus-settings")->assertNotFound();
    }

    public function test_b2b_user_sees_b2b_store_bonus_settings(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();
        $store = Store::factory()->b2b()->create([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 7,
            'bonus_spend_percent' => 20,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}/bonus-settings");

        $response->assertOk()
            ->assertJsonPath('data.isEnabled', true)
            ->assertJsonPath('data.earnPercent', 7)
            ->assertJsonPath('data.spendPercent', 20);
    }
}
