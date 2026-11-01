<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Store;

use App\Models\Address;
use App\Models\Store;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AvailableStoreTest extends TestCase
{
    use RefreshDatabase;

    private FakeSlotsApiClient $fakeSlotsApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSlotsApi = $this->app->make(SlotsApiClientInterface::class);
    }

    // ==================== Get Available Stores ====================

    public function test_get_stores_with_address_id_returns_only_available_stores(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $store1 = Store::factory()->create(['code' => '1', 'name' => 'Магазин 1']);
        $store2 = Store::factory()->create(['code' => '2', 'name' => 'Магазин 2']);
        Store::factory()->create(['code' => '3', 'name' => 'Магазин 3']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
            ['id' => 2, 'code' => '2', 'delivery_price' => 150, 'min_order_price' => 300],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $store1->id)
            ->assertJsonPath('data.0.deliveryInfo.deliveryPrice', 200)
            ->assertJsonPath('data.0.deliveryInfo.minOrderAmount', 500)
            ->assertJsonPath('data.1.id', $store2->id)
            ->assertJsonPath('data.1.deliveryInfo.deliveryPrice', 150)
            ->assertJsonPath('data.1.deliveryInfo.minOrderAmount', 300);
    }

    public function test_get_stores_without_address_id_returns_all_stores(): void
    {
        Store::factory()->create(['name' => 'Магазин 1']);
        Store::factory()->create(['name' => 'Магазин 2']);
        Store::factory()->create(['name' => 'Магазин 3']);

        $response = $this->getJson('/api/v1/stores');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('total', 3)
            ->assertJsonMissing(['deliveryInfo']);
    }

    public function test_get_stores_with_other_users_address_returns_403(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $address = Address::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");

        $response->assertForbidden()
            ->assertJsonPath('error', 'forbidden');
    }

    public function test_get_stores_with_address_missing_coordinates_returns_422(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => null,
            'longitude' => null,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'address_missing_coordinates');
    }

    public function test_get_stores_with_zero_coordinates_returns_422(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'address_missing_coordinates');
    }

    public function test_get_stores_with_nonexistent_address_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/stores?addressId=99999');

        $response->assertNotFound()
            ->assertJsonPath('error', 'address_not_found');
    }

    public function test_get_stores_with_address_ignores_address_for_guests(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);
        Store::factory()->create(['code' => '1']);

        $response = $this->getJson("/api/v1/stores?addressId={$address->id}");

        // Без Bearer-токена addressId игнорируется: отдаётся общий список магазинов
        $response->assertOk()
            ->assertJsonStructure(['hasMore', 'data', 'total'])
            ->assertJsonPath('total', 1);
    }

    public function test_get_stores_when_slots_api_unavailable_returns_empty_list(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        Store::factory()->create(['code' => '1']);

        $this->fakeSlotsApi->setShouldFail(true);

        $response = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_caching_works_correctly(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $store = Store::factory()->create(['code' => '1']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $response1 = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");
        $response1->assertOk()->assertJsonCount(1, 'data');

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 999, 'min_order_price' => 999],
        ]);

        $response2 = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");
        $response2->assertOk()
            ->assertJsonPath('data.0.deliveryInfo.deliveryPrice', 200)
            ->assertJsonPath('data.0.deliveryInfo.minOrderAmount', 500);
    }

    public function test_cache_invalidates_when_address_coordinates_change(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $store = Store::factory()->create(['code' => '1']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 999, 'min_order_price' => 999],
        ]);

        $address->update(['latitude' => 55.8000]);

        $response = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");
        $response->assertOk()
            ->assertJsonPath('data.0.deliveryInfo.deliveryPrice', 999)
            ->assertJsonPath('data.0.deliveryInfo.minOrderAmount', 999);
    }

    public function test_filters_out_inactive_stores(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        Store::factory()->create(['code' => '1', 'name' => 'Активный']);
        Store::factory()->inactive()->create(['code' => '2', 'name' => 'Неактивный']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
            ['id' => 2, 'code' => '2', 'delivery_price' => 150, 'min_order_price' => 300],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Активный');
    }

    public function test_skips_stores_not_found_in_database(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        Store::factory()->create(['code' => '1', 'name' => 'Существующий']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
            ['id' => 999, 'code' => '999', 'delivery_price' => 150, 'min_order_price' => 300],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Существующий');
    }

    public function test_pagination_works_with_address_filter(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $shops = [];
        for ($i = 1; $i <= 25; $i++) {
            Store::factory()->create(['code' => (string) $i, 'name' => "Магазин {$i}"]);
            $shops[] = ['id' => $i, 'code' => (string) $i, 'delivery_price' => 100, 'min_order_price' => 500];
        }

        $this->fakeSlotsApi->setShops($shops);

        $response = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}&page=1&perPage=10");

        $response->assertOk()
            ->assertJsonPath('hasMore', true)
            ->assertJsonPath('total', 25)
            ->assertJsonCount(10, 'data');

        $response2 = $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}&page=3&perPage=10");

        $response2->assertOk()
            ->assertJsonPath('hasMore', false)
            ->assertJsonCount(5, 'data');
    }
}
