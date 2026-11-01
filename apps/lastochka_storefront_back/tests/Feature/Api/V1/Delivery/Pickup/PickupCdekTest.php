<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery\Pickup;

use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PickupCdekTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeSlotsApiClient
    {
        /** @var FakeSlotsApiClient */
        return app(SlotsApiClientInterface::class);
    }

    // ==================== CDEK PVZ List ====================

    public function test_returns_cdek_pvz_list(): void
    {
        $this->fake()->setCdekPvz([
            [
                'provider' => 'cdek',
                'external_id' => 'MKHCH19',
                'code' => 'MKHCH19',
                'title' => 'ПВЗ на Гамидова',
                'address' => 'ул. Гамидова, 42',
                'city' => 'Махачкала',
                'postalCode' => '367000',
                'lat' => 42.98,
                'lng' => 47.50,
                'workTime' => '09:00-18:00',
                'phone' => '+79641234567',
                'type' => 'PVZ',
            ],
        ]);

        $response = $this->getJson('/api/v1/pickup/cdek?bbox=42.97,47.49,42.99,47.51');

        $response->assertOk()
            ->assertJsonPath('data.0.code', 'MKHCH19')
            ->assertJsonPath('data.0.provider', 'cdek')
            ->assertJsonPath('data.0.city', 'Махачкала');
    }

    public function test_returns_empty_cdek_pvz_list(): void
    {
        $this->fake()->setCdekPvz([]);

        $response = $this->getJson('/api/v1/pickup/cdek');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_cdek_pvz_degradation_on_failure(): void
    {
        $this->fake()->setShouldFail(true);

        $response = $this->getJson('/api/v1/pickup/cdek');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ==================== CDEK PVZ Show ====================

    public function test_returns_cdek_pvz_by_code(): void
    {
        $this->fake()->setCdekPickup([
            'provider' => 'cdek',
            'code' => 'MKHCH19',
            'title' => 'ПВЗ на Гамидова',
            'lat' => 42.98,
            'lng' => 47.50,
        ]);

        $response = $this->getJson('/api/v1/pickup/cdek/MKHCH19');

        $response->assertOk()
            ->assertJsonPath('data.code', 'MKHCH19');
    }

    public function test_returns_404_when_cdek_pvz_not_found(): void
    {
        $this->fake()->setCdekPickup(null, notFound: true);

        $response = $this->getJson('/api/v1/pickup/cdek/NOTEXIST');

        $response->assertNotFound()
            ->assertJsonPath('message', 'ПВЗ СДЭК не найден');
    }
}
