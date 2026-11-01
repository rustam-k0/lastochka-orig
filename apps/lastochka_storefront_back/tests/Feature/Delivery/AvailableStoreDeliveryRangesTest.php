<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Address;
use App\Models\Store;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use App\Services\SlotsApi\SlotsApiClient;
use App\Services\Store\AvailableStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280: ступени зоны из /slots/shops (ключ delivery_price_ranges) в
 * AvailableStoreService и SlotsApiClient; старые записи кэша без ключа.
 */
final class AvailableStoreDeliveryRangesTest extends TestCase
{
    use RefreshDatabase;
    use TogglesDeliveryPriceRangesModule;

    protected function setUp(): void
    {
        parent::setUp();
        // Тесты написаны до тумблера (Task 10): модуль включён, поведение прежнее.
        $this->setDeliveryPriceRangesModule(true);
    }

    private function addressAndStore(): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);
        $store = Store::factory()->create(['code' => 'shop-280']);

        return [$address, $store];
    }

    public function test_delivery_info_contains_zone_ranges(): void
    {
        [$address, $store] = $this->addressAndStore();

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([[
            'id' => 1,
            'code' => 'shop-280',
            'delivery_price' => 200,
            'min_order_price' => 500,
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ],
        ]]);

        $info = app(AvailableStoreService::class)->getDeliveryInfo($address, $store);

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $info['deliveryPriceRanges']);
    }

    public function test_shop_without_ranges_key_gets_free_threshold_step(): void
    {
        [$address, $store] = $this->addressAndStore();

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([[
            'id' => 1,
            'code' => 'shop-280',
            'delivery_price' => 200,
            'min_order_price' => 500,
            'free_delivery_from' => 3000,
        ]]);

        $info = app(AvailableStoreService::class)->getDeliveryInfo($address, $store);

        $this->assertSame([['from_amount' => 3000, 'price' => 0]], $info['deliveryPriceRanges']);
    }

    public function test_old_cache_entry_without_ranges_is_read(): void
    {
        [$address, $store] = $this->addressAndStore();

        // Запись кэша до выкладки: store_data без delivery_price_ranges
        Cache::put('available_stores:address:'.$address->id, [
            'store_codes' => ['shop-280'],
            'store_data' => ['shop-280' => [
                'delivery_price' => 150,
                'min_order_price' => 500,
                'free_delivery_from' => 1000,
            ]],
            'cached_at' => now()->toIso8601String(),
        ], 3600);

        $info = app(AvailableStoreService::class)->getDeliveryInfo($address, $store);

        $this->assertSame(150, $info['deliveryPrice']);
        $this->assertSame(1000, $info['freeDeliveryFrom']);
        $this->assertSame([['from_amount' => 1000, 'price' => 0]], $info['deliveryPriceRanges']);
    }

    public function test_slots_api_client_parses_delivery_price_ranges(): void
    {
        config(['services.slots_api.base_url' => 'https://api.slots.test']);

        Http::fake([
            'api.slots.test/api/v1/slots/shops*' => Http::response([
                'shops' => [
                    [
                        'id' => 1,
                        'code' => 'shop-1',
                        'delivery_price' => 200,
                        'min_order_price' => 500,
                        'free_delivery_from' => 3000,
                        'delivery_price_ranges' => [
                            ['from_amount' => 3000, 'price' => 0],
                            ['from_amount' => 1000, 'price' => 100],
                        ],
                    ],
                    [
                        'id' => 2,
                        'code' => 'shop-2',
                        'delivery_price' => 150,
                        'min_order_price' => 300,
                    ],
                ],
            ], 200),
        ]);

        $result = (new SlotsApiClient)->getAvailableShops(55.7558, 37.6173);

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $result[0]['delivery_price_ranges']);
        $this->assertSame([], $result[1]['delivery_price_ranges']);
    }
}
