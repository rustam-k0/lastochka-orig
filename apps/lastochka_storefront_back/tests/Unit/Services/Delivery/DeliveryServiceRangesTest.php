<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use App\DTOs\Delivery\YandexDeliveryMethodsData;
use App\Exceptions\DeliverySystemException;
use App\Models\Address;
use App\Models\IntegrationConfig;
use App\Models\Store;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\FakeDeliveryClient;
use App\Services\YandexDelivery\FakeYandexGoClient;
use App\Services\YandexDelivery\YandexGoClientInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * MID-280: ступени зоны доезжают через DeliveryService до потребителей, в том
 * числе через все пересборки DTO для Яндекс-доставки; кэш сменил префикс.
 */
final class DeliveryServiceRangesTest extends TestCase
{
    use RefreshDatabase;

    private const RANGES = [
        ['from_amount' => 1000, 'price' => 100],
        ['from_amount' => 3000, 'price' => 0],
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $store = Store::factory()->create([
            'code' => 'STORE-280',
            'latitude' => 55.75,
            'longitude' => 37.61,
        ]);
        $address = Address::factory()->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        return [$store, $address];
    }

    private function enableYandex(): void
    {
        config(['yandex-delivery.enabled' => true]);

        IntegrationConfig::create([
            'integration_type' => 'delivery',
            'driver' => 'yandex_go',
            'credentials' => [
                'oauth_token' => 'test_token',
                'emergency_phone' => '+79991234567',
                'callback_url' => 'https://test.com/webhook',
            ],
            'is_active' => true,
        ]);
    }

    private function yandexClient(): FakeDeliveryClient
    {
        $client = new FakeDeliveryClient;
        $client->deliveryType = 'yandex';
        $client->deliveryPriceRanges = [['from_amount' => 3000, 'price' => 0]];

        return $client;
    }

    public function test_own_zone_ranges_are_returned(): void
    {
        [$store, $address] = $this->fixture();
        $client = new FakeDeliveryClient;
        $client->deliveryPriceRanges = self::RANGES;

        $info = (new DeliveryService($client, new FakeYandexGoClient))
            ->getDeliveryInfo($store, $address, now()->addDay()->format('Y-m-d'));

        $this->assertSame(self::RANGES, $info->deliveryPriceRanges);
    }

    public function test_yandex_enrichment_keeps_ranges_on_every_branch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));
        [$store, $address] = $this->fixture();
        $this->enableYandex();
        $date = '2026-07-02';

        // 1. Основная ветка: express разрешён, цена получена, слоты есть
        $info = (new DeliveryService($this->yandexClient(), new FakeYandexGoClient))
            ->getDeliveryInfo($store, $address, $date);
        $this->assertTrue($info->yandexDelivery->available);
        $this->assertSame([['from_amount' => 3000, 'price' => 0]], $info->deliveryPriceRanges);

        // 2. express не разрешён
        $yandex = new FakeYandexGoClient;
        $yandex->expressAllowed = false;
        $info = (new DeliveryService($this->yandexClient(), $yandex))->getDeliveryInfo($store, $address, $date);
        $this->assertFalse($info->yandexDelivery->available);
        $this->assertSame([['from_amount' => 3000, 'price' => 0]], $info->deliveryPriceRanges);

        // 3. checkPrice упал
        $failing = Mockery::mock(YandexGoClientInterface::class);
        $failing->shouldReceive('getDeliveryMethods')->andReturn(new YandexDeliveryMethodsData(expressAllowed: true));
        $failing->shouldReceive('checkPrice')->andThrow(new \RuntimeException('Yandex API unavailable'));
        $info = (new DeliveryService($this->yandexClient(), $failing))->getDeliveryInfo($store, $address, $date);
        $this->assertFalse($info->yandexDelivery->available);
        $this->assertSame([['from_amount' => 3000, 'price' => 0]], $info->deliveryPriceRanges);

        // 4. Слотов в окне Яндекса нет
        Carbon::setTestNow(Carbon::parse('2026-07-02 21:30:00', config('app.timezone')));
        $info = (new DeliveryService($this->yandexClient(), new FakeYandexGoClient))->getDeliveryInfo($store, $address, $date);
        $this->assertSame([], $info->slots);
        $this->assertSame([['from_amount' => 3000, 'price' => 0]], $info->deliveryPriceRanges);
    }

    public function test_fallback_reads_new_cache_prefix(): void
    {
        [$store, $address] = $this->fixture();
        $date = '2025-01-15';
        $cacheKey = "delivery_slots:v2:{$store->code}:{$address->latitude}:{$address->longitude}:{$date}:".md5(json_encode([]));
        Cache::forever($cacheKey, new DeliveryInfoData(
            slots: [],
            minOrderAmount: 500,
            freeDeliveryFrom: 3000,
            deliveryPrice: 200,
            deliveryPriceRanges: self::RANGES,
        ));

        $client = Mockery::mock(DeliveryClientInterface::class);
        $client->shouldReceive('getSlots')->once()->andThrow(new DeliverySystemException('API Error'));

        $info = (new DeliveryService($client, new FakeYandexGoClient))->getDeliveryInfo($store, $address, $date);

        $this->assertSame(self::RANGES, $info->deliveryPriceRanges);
        Cache::forget($cacheKey);
    }

    public function test_entry_under_old_prefix_is_not_used(): void
    {
        [$store, $address] = $this->fixture();
        $date = '2025-01-15';
        // Объект DTO, закэшированный до выкладки, лежит под старым префиксом
        $oldKey = "delivery_slots:{$store->code}:{$address->latitude}:{$address->longitude}:{$date}:".md5(json_encode([]));
        Cache::forever($oldKey, new DeliveryInfoData(slots: [], minOrderAmount: 500, freeDeliveryFrom: null, deliveryPrice: 200));

        $client = Mockery::mock(DeliveryClientInterface::class);
        $client->shouldReceive('getSlots')->once()->andThrow(new DeliverySystemException('API Error'));

        $this->expectException(DeliverySystemException::class);

        try {
            (new DeliveryService($client, new FakeYandexGoClient))->getDeliveryInfo($store, $address, $date);
        } finally {
            Cache::forget($oldKey);
        }
    }
}
