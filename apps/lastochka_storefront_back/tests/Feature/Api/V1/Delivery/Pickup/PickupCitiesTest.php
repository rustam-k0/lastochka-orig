<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery\Pickup;

use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PickupCitiesTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeSlotsApiClient
    {
        /** @var FakeSlotsApiClient */
        return app(SlotsApiClientInterface::class);
    }

    public function test_returns_pickup_cities(): void
    {
        $this->fake()->setPickupCities([
            ['name' => 'Махачкала', 'slug' => 'makhachkala', 'region' => 'Дагестан', 'zip' => '367000', 'lat' => 42.98, 'lng' => 47.50],
            ['name' => 'Москва', 'slug' => 'moscow', 'region' => 'Московская область', 'zip' => '101000', 'lat' => 55.76, 'lng' => 37.61],
        ]);

        $response = $this->getJson('/api/v1/pickup/cities');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Махачкала');
    }

    public function test_returns_filtered_cities(): void
    {
        $this->fake()->setPickupCities([
            ['name' => 'Махачкала', 'slug' => 'makhachkala', 'region' => 'Дагестан', 'zip' => '367000', 'lat' => 42.98, 'lng' => 47.50],
        ]);

        $response = $this->getJson('/api/v1/pickup/cities?q=Мах&limit=5');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_returns_empty_cities_on_failure(): void
    {
        $this->fake()->setShouldFail(true);

        $response = $this->getJson('/api/v1/pickup/cities?q=Москва');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
