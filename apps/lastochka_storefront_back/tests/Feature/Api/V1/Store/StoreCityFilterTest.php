<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Store;

use App\Models\Address;
use App\Models\City;
use App\Models\Store;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Фильтр магазинов по городу: GET /api/v1/stores?cityId=...
 */
final class StoreCityFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_stores_of_requested_city(): void
    {
        $krasnoyarsk = City::factory()->create(['name' => 'Красноярск']);
        $moscow = City::factory()->create(['name' => 'Москва']);

        $inCity = Store::factory()->create(['name' => 'Магазин А', 'city_id' => $krasnoyarsk->id]);
        Store::factory()->create(['name' => 'Магазин Б', 'city_id' => $moscow->id]);

        $this->getJson("/api/v1/stores?cityId={$krasnoyarsk->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $inCity->id)
            ->assertJsonPath('data.0.city.id', $krasnoyarsk->id);
    }

    public function test_stores_without_city_are_excluded_when_filtering(): void
    {
        $city = City::factory()->create();
        Store::factory()->create(['name' => 'Без города', 'city_id' => null]);

        $this->getJson("/api/v1/stores?cityId={$city->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }

    public function test_without_city_id_all_stores_returned(): void
    {
        $city = City::factory()->create();
        Store::factory()->create(['city_id' => $city->id]);
        Store::factory()->create(['city_id' => null]);

        $this->getJson('/api/v1/stores')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_city_filter_applies_to_pickup_mode(): void
    {
        $city = City::factory()->create();
        $otherCity = City::factory()->create();

        $pickupInCity = Store::factory()->create([
            'city_id' => $city->id,
            'is_pickup_enabled' => true,
        ]);
        Store::factory()->create([
            'city_id' => $city->id,
            'is_pickup_enabled' => false,
        ]);
        Store::factory()->create([
            'city_id' => $otherCity->id,
            'is_pickup_enabled' => true,
        ]);

        $this->getJson("/api/v1/stores?shippingMethod=pickup&cityId={$city->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pickupInCity->id);
    }

    public function test_city_filter_applies_to_address_mode(): void
    {
        /** @var FakeSlotsApiClient $fakeSlotsApi */
        $fakeSlotsApi = $this->app->make(SlotsApiClientInterface::class);

        $city = City::factory()->create();
        $otherCity = City::factory()->create();

        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create([
            'latitude' => 56.0124,
            'longitude' => 92.8553,
        ]);

        $inCity = Store::factory()->create(['code' => 'A', 'city_id' => $city->id]);
        Store::factory()->create(['code' => 'B', 'city_id' => $otherCity->id]);

        $fakeSlotsApi->setShops([
            ['id' => 1, 'code' => 'A', 'delivery_price' => 200, 'min_order_price' => 500],
            ['id' => 2, 'code' => 'B', 'delivery_price' => 150, 'min_order_price' => 300],
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/stores?addressId={$address->id}&cityId={$city->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $inCity->id);
    }

    public function test_unknown_city_returns_empty_list(): void
    {
        Store::factory()->create(['city_id' => null]);

        $this->getJson('/api/v1/stores?cityId=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }

    public function test_invalid_city_id_is_rejected(): void
    {
        $this->getJson('/api/v1/stores?cityId=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('cityId');
    }
}
