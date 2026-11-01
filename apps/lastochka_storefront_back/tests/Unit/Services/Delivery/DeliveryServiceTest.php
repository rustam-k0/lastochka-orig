<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use App\DTOs\Delivery\DeliverySlotData;
use App\Exceptions\DeliverySystemException;
use App\Exceptions\DeliveryZoneNotFoundException;
use App\Models\Address;
use App\Models\Store;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\DeliveryService;
use App\Services\YandexDelivery\FakeYandexGoClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class DeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDeliveryInfo(): DeliveryInfoData
    {
        $start1 = Carbon::parse('2025-01-15 10:00:00');
        $end1 = Carbon::parse('2025-01-15 12:00:00');

        return new DeliveryInfoData(
            slots: [
                new DeliverySlotData(
                    id: $start1->timestamp,
                    timeSlot: '10:00-12:00',
                    startAt: $start1,
                    endAt: $end1,
                ),
            ],
            minOrderAmount: 500,
            freeDeliveryFrom: 1500,
            deliveryPrice: 200,
        );
    }

    // ==================== getDeliveryInfo ====================

    public function test_get_delivery_info_success(): void
    {
        $store = Store::factory()->create(['code' => 'STORE-001']);
        $address = Address::factory()->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);
        $date = '2025-01-15';
        $fakeInfo = $this->fakeDeliveryInfo();

        $client = Mockery::mock(DeliveryClientInterface::class);
        $client->shouldReceive('getSlots')
            ->with('STORE-001', 55.7558, 37.6173, $date, [])
            ->once()
            ->andReturn($fakeInfo);

        $service = new DeliveryService($client, new FakeYandexGoClient);

        $result = $service->getDeliveryInfo($store, $address, $date);

        $this->assertEquals($fakeInfo->minOrderAmount, $result->minOrderAmount);
        $this->assertEquals($fakeInfo->deliveryPrice, $result->deliveryPrice);
        $this->assertCount(count($fakeInfo->slots), $result->slots);
    }

    public function test_get_delivery_info_fallback_on_error(): void
    {
        $store = Store::factory()->create(['code' => 'STORE-001']);
        $address = Address::factory()->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);
        $date = '2025-01-15';
        // Ключ кеша включает хеш состава корзины: слоты зависят от времени сборки.
        // Префикс v2 (MID-280): объекты DTO до выкладки не читаются.
        $cacheKey = "delivery_slots:v2:{$store->code}:{$address->latitude}:{$address->longitude}:{$date}:".md5(json_encode([]));

        $cachedInfo = $this->fakeDeliveryInfo();
        Cache::forever($cacheKey, $cachedInfo);

        $client = Mockery::mock(DeliveryClientInterface::class);
        $client->shouldReceive('getSlots')
            ->once()
            ->andThrow(new DeliverySystemException('API Error'));

        $service = new DeliveryService($client, new FakeYandexGoClient);

        $result = $service->getDeliveryInfo($store, $address, $date);

        $this->assertEquals($cachedInfo->minOrderAmount, $result->minOrderAmount);
        Cache::forget($cacheKey);
    }

    public function test_get_delivery_info_throws_when_no_cache(): void
    {
        $store = Store::factory()->create(['code' => 'STORE-001']);
        $address = Address::factory()->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);
        $date = '2025-01-15';
        $cacheKey = "delivery_slots:STORE-001:55.7558:37.6173:{$date}:".md5(json_encode([]));

        Cache::forget($cacheKey);

        $client = Mockery::mock(DeliveryClientInterface::class);
        $client->shouldReceive('getSlots')
            ->once()
            ->andThrow(new DeliverySystemException('API Error'));

        $service = new DeliveryService($client, new FakeYandexGoClient);

        $this->expectException(DeliverySystemException::class);

        $service->getDeliveryInfo($store, $address, $date);
    }

    public function test_get_delivery_info_throws_zone_not_found_without_fallback(): void
    {
        $store = Store::factory()->create(['code' => 'STORE-001']);
        $address = Address::factory()->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);
        $date = '2025-01-15';
        $cacheKey = "delivery_slots:STORE-001:55.7558:37.6173:$date";

        // Кладём данные в кеш — они НЕ должны использоваться при zone_not_found
        Cache::forever($cacheKey, $this->fakeDeliveryInfo());

        $client = Mockery::mock(DeliveryClientInterface::class);
        $client->shouldReceive('getSlots')
            ->once()
            ->andThrow(new DeliveryZoneNotFoundException);

        $service = new DeliveryService($client, new FakeYandexGoClient);

        $this->expectException(DeliveryZoneNotFoundException::class);

        $service->getDeliveryInfo($store, $address, $date);
    }

    // ==================== слот по его id ====================

    public function test_slot_is_built_from_slot_id(): void
    {
        // DeliveryService::resolveSlot() больше нет — построение слота по его
        // id (timestamp начала) переехало в DeliverySlotData::fromSlotId().
        $start = Carbon::parse('2025-01-15 10:00:00', config('app.timezone'));
        $timestamp = $start->timestamp;

        $result = DeliverySlotData::fromSlotId($timestamp);

        $this->assertEquals($timestamp, $result->id);
        $this->assertEquals('10:00-12:00', $result->timeSlot);
        $this->assertEquals($start->format('Y-m-d H:i:s'), $result->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals($start->copy()->addHours(2)->format('Y-m-d H:i:s'), $result->endAt->format('Y-m-d H:i:s'));
    }
}
