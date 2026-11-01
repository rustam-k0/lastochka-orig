<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\DTOs\Geo\IpLocationData;
use App\Models\City;
use App\Models\Store;
use App\Services\Geo\Contracts\IpGeolocatorInterface;
use App\Services\Geo\FakeIpGeolocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientIpTest extends TestCase
{
    use RefreshDatabase;

    private function fakeLocation(?IpLocationData $location): void
    {
        /** @var FakeIpGeolocator $geolocator */
        $geolocator = $this->app->make(IpGeolocatorInterface::class);
        $geolocator->setLocation($location);
    }

    public function test_returns_ip_from_x_forwarded_for(): void
    {
        $response = $this->getJson('/api/v1/client-ip', [
            'X-Forwarded-For' => '203.0.113.5, 10.0.0.1',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.ip', '203.0.113.5');
    }

    public function test_returns_connection_ip_without_forwarded_header(): void
    {
        $response = $this->getJson('/api/v1/client-ip');

        $response->assertOk()
            ->assertJsonPath('data.ip', '127.0.0.1');
    }

    public function test_does_not_require_auth(): void
    {
        $response = $this->getJson('/api/v1/client-ip');

        $response->assertOk();
    }

    public function test_returns_city_when_client_city_has_active_stores(): void
    {
        $city = City::factory()->create(['name' => 'Красноярск']);
        Store::factory()->create(['city_id' => $city->id, 'is_default' => true]);

        $this->fakeLocation(new IpLocationData(countryCode: 'RU', city: 'Красноярск'));

        $this->getJson('/api/v1/client-ip')
            ->assertOk()
            ->assertJsonPath('data.city.id', $city->id)
            ->assertJsonPath('data.city.name', 'Красноярск');
    }

    public function test_city_payload_includes_default_store_id(): void
    {
        $city = City::factory()->create(['name' => 'Красноярск']);
        $store = Store::factory()->create(['city_id' => $city->id, 'is_default' => true]);

        $this->fakeLocation(new IpLocationData(countryCode: 'RU', city: 'Красноярск'));

        $this->getJson('/api/v1/client-ip')
            ->assertOk()
            ->assertJsonPath('data.city.defaultStoreId', $store->id);
    }

    public function test_returns_null_city_when_no_stores_in_client_city(): void
    {
        City::factory()->create(['name' => 'Кодинск']);

        $this->fakeLocation(new IpLocationData(countryCode: 'RU', city: 'Кодинск'));

        $this->getJson('/api/v1/client-ip')
            ->assertOk()
            ->assertJsonPath('data.city', null);
    }

    public function test_returns_null_city_when_city_has_only_inactive_stores(): void
    {
        $city = City::factory()->create(['name' => 'Кодинск']);
        Store::factory()->inactive()->create(['city_id' => $city->id]);

        $this->fakeLocation(new IpLocationData(countryCode: 'RU', city: 'Кодинск'));

        $this->getJson('/api/v1/client-ip')
            ->assertOk()
            ->assertJsonPath('data.city', null);
    }

    public function test_returns_null_city_when_city_is_inactive(): void
    {
        $city = City::factory()->inactive()->create(['name' => 'Кодинск']);
        Store::factory()->create(['city_id' => $city->id]);

        $this->fakeLocation(new IpLocationData(countryCode: 'RU', city: 'Кодинск'));

        $this->getJson('/api/v1/client-ip')
            ->assertOk()
            ->assertJsonPath('data.city', null);
    }

    public function test_returns_null_city_when_client_city_unknown_to_catalog(): void
    {
        $this->fakeLocation(new IpLocationData(countryCode: 'RU', city: 'Норильск'));

        $this->getJson('/api/v1/client-ip')
            ->assertOk()
            ->assertJsonPath('data.city', null);
    }

    public function test_returns_null_city_when_provider_did_not_resolve_city(): void
    {
        $this->fakeLocation(new IpLocationData(countryCode: 'RU', city: null));

        $this->getJson('/api/v1/client-ip')
            ->assertOk()
            ->assertJsonPath('data.city', null);
    }

    public function test_returns_null_city_when_location_unknown(): void
    {
        // Дефолт FakeIpGeolocator — локация не определена (null): приватный
        // IP или сбой гео-провайдера. Это НЕ должно приводить к 403.
        $this->getJson('/api/v1/client-ip')
            ->assertOk()
            ->assertJsonPath('data.city', null);
    }

    public function test_returns_403_when_client_outside_russia(): void
    {
        City::factory()->create(['name' => 'Алматы']);

        $this->fakeLocation(new IpLocationData(countryCode: 'KZ', city: 'Алматы'));

        $this->getJson('/api/v1/client-ip')
            ->assertForbidden();
    }
}
