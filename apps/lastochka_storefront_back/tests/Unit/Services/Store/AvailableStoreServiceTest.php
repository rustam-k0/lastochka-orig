<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Store;

use App\Models\Address;
use App\Models\Store;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use App\Services\Store\AvailableStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class AvailableStoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeSlotsApiClient $fakeSlotsApi;

    private AvailableStoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSlotsApi = $this->app->make(SlotsApiClientInterface::class);
        $this->service = $this->app->make(AvailableStoreService::class);
    }

    public function test_get_available_stores_maps_by_store_code(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $store1 = Store::factory()->create(['code' => '1']);
        $store2 = Store::factory()->create(['code' => '2']);
        Store::factory()->create(['code' => '3']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
            ['id' => 2, 'code' => '2', 'delivery_price' => 150, 'min_order_price' => 300],
        ]);

        $stores = $this->service->getAvailableStores($address);

        $this->assertCount(2, $stores);
        $this->assertTrue($stores->contains('id', $store1->id));
        $this->assertTrue($stores->contains('id', $store2->id));
    }

    public function test_get_available_stores_filters_inactive_stores(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        Store::factory()->create(['code' => '1', 'is_active' => true]);
        Store::factory()->create(['code' => '2', 'is_active' => false]);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
            ['id' => 2, 'code' => '2', 'delivery_price' => 150, 'min_order_price' => 300],
        ]);

        $stores = $this->service->getAvailableStores($address);

        $this->assertCount(1, $stores);
        $this->assertEquals('1', $stores->first()->code);
    }

    public function test_get_delivery_info_returns_correct_data(): void
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

        $deliveryInfo = $this->service->getDeliveryInfo($address, $store);

        $this->assertNotNull($deliveryInfo);
        $this->assertEquals(200, $deliveryInfo['deliveryPrice']);
        $this->assertEquals(500, $deliveryInfo['minOrderAmount']);
    }

    public function test_get_delivery_info_returns_null_for_unknown_store(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $store = Store::factory()->create(['code' => '999']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $deliveryInfo = $this->service->getDeliveryInfo($address, $store);

        $this->assertNull($deliveryInfo);
    }

    public function test_invalidate_cache_removes_cache_entry(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        Store::factory()->create(['code' => '1']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $this->service->getAvailableStores($address);
        $this->assertTrue(Cache::has("available_stores:address:{$address->id}"));

        $this->service->invalidateCache($address->id);
        $this->assertFalse(Cache::has("available_stores:address:{$address->id}"));
    }

    public function test_invalidate_all_address_caches_removes_all_entries(): void
    {
        $user = User::factory()->create();
        $addressA = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);
        $addressB = Address::factory()->for($user)->create([
            'latitude' => 43.2520,
            'longitude' => 46.5870,
        ]);

        Store::factory()->create(['code' => '1']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $this->service->getAvailableStores($addressA);
        $this->service->getAvailableStores($addressB);

        $this->assertTrue(Cache::has("available_stores:address:{$addressA->id}"));
        $this->assertTrue(Cache::has("available_stores:address:{$addressB->id}"));

        $this->service->invalidateAllAddressCaches();

        $this->assertFalse(Cache::has("available_stores:address:{$addressA->id}"));
        $this->assertFalse(Cache::has("available_stores:address:{$addressB->id}"));
    }

    public function test_refresh_cache_updates_cache_with_fresh_data(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        Store::factory()->create(['code' => '1']);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $this->service->getAvailableStores($address);

        $this->fakeSlotsApi->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 999, 'min_order_price' => 999],
        ]);

        $this->service->refreshCache($address);

        $deliveryInfo = $this->service->getDeliveryInfo($address, Store::first());

        $this->assertEquals(999, $deliveryInfo['deliveryPrice']);
        $this->assertEquals(999, $deliveryInfo['minOrderAmount']);
    }

    public function test_returns_empty_collection_when_no_stores_available(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        Store::factory()->create(['code' => '1']);

        $this->fakeSlotsApi->setShops([]);

        $stores = $this->service->getAvailableStores($address);

        $this->assertCount(0, $stores);
    }

    public function test_returns_empty_collection_when_api_fails(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        Store::factory()->create(['code' => '1']);

        $this->fakeSlotsApi->setShouldFail(true);

        $stores = $this->service->getAvailableStores($address);

        $this->assertCount(0, $stores);
    }
}
