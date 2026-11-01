<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Store;

use App\Models\City;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ближайший магазин по координатам: GET /api/v1/stores/nearest
 */
final class NearestStoreTest extends TestCase
{
    use RefreshDatabase;

    /** Красноярск, пл. Революции. */
    private const LAT = 56.0124;

    private const LNG = 92.8553;

    public function test_returns_closest_store(): void
    {
        $far = Store::factory()->create([
            'name' => 'Дальний',
            'latitude' => 55.7558,
            'longitude' => 37.6173, // Москва
        ]);
        $near = Store::factory()->create([
            'name' => 'Ближний',
            'latitude' => 56.0150,
            'longitude' => 92.8600,
        ]);

        $response = $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->assertJsonPath('data.id', $near->id);

        $this->assertNotSame($far->id, $response->json('data.id'));
        $this->assertLessThan(1, $response->json('data.distanceKm'));
    }

    public function test_distance_is_returned_in_kilometers(): void
    {
        // ~1 градус широты ≈ 111 км
        Store::factory()->create([
            'latitude' => self::LAT + 1,
            'longitude' => self::LNG,
        ]);

        $distance = $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->json('data.distanceKm');

        $this->assertEqualsWithDelta(111.19, $distance, 0.5);
    }

    public function test_stores_without_coordinates_are_skipped(): void
    {
        Store::factory()->create([
            'name' => 'Без координат',
            'latitude' => null,
            'longitude' => null,
        ]);
        $withCoordinates = Store::factory()->create([
            'latitude' => 60.0,
            'longitude' => 100.0,
        ]);

        $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->assertJsonPath('data.id', $withCoordinates->id);
    }

    public function test_inactive_stores_are_skipped(): void
    {
        Store::factory()->inactive()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
        $active = Store::factory()->create([
            'latitude' => 56.5,
            'longitude' => 93.5,
        ]);

        $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->assertJsonPath('data.id', $active->id);
    }

    public function test_city_filter_narrows_search(): void
    {
        $city = City::factory()->create();

        Store::factory()->create([
            'name' => 'Ближний без города',
            'city_id' => null,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
        $inCity = Store::factory()->create([
            'name' => 'Дальний в городе',
            'city_id' => $city->id,
            'latitude' => 56.5,
            'longitude' => 93.5,
        ]);

        $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG."&cityId={$city->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $inCity->id);
    }

    public function test_pickup_filter_narrows_search(): void
    {
        Store::factory()->create([
            'is_pickup_enabled' => false,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
        $pickup = Store::factory()->create([
            'is_pickup_enabled' => true,
            'latitude' => 56.5,
            'longitude' => 93.5,
        ]);

        $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG.'&shippingMethod=pickup')
            ->assertOk()
            ->assertJsonPath('data.id', $pickup->id);
    }

    public function test_radius_excludes_distant_stores(): void
    {
        Store::factory()->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173, // Москва, > 2500 км
        ]);

        $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG.'&radius=50')
            ->assertStatus(404)
            ->assertJsonPath('error', 'store_not_found');
    }

    public function test_radius_keeps_stores_inside_it(): void
    {
        $store = Store::factory()->create([
            'latitude' => 56.0150,
            'longitude' => 92.8600,
        ]);

        $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG.'&radius=50')
            ->assertOk()
            ->assertJsonPath('data.id', $store->id);
    }

    public function test_returns_404_when_no_stores_with_coordinates(): void
    {
        Store::factory()->create(['latitude' => null, 'longitude' => null]);

        $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertStatus(404)
            ->assertJsonPath('error', 'store_not_found');
    }

    public function test_coordinates_are_required(): void
    {
        $this->getJson('/api/v1/stores/nearest')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_coordinates_are_validated(): void
    {
        $this->getJson('/api/v1/stores/nearest?latitude=100&longitude=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_endpoint_is_public(): void
    {
        Store::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $this->getJson('/api/v1/stores/nearest?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk();
    }

    public function test_route_does_not_shadow_store_details(): void
    {
        $store = Store::factory()->create();

        $this->getJson("/api/v1/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $store->id);
    }
}
