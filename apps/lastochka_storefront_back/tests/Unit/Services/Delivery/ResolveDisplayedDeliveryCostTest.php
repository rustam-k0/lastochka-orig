<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use App\Models\Address;
use App\Models\Store;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\FakeDeliveryClient;
use App\Services\YandexDelivery\FakeYandexGoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280: превью стоимости доставки. Своя доставка по ступеням зоны, у Яндекса
 * из лесенки действует только бесплатная ступень, иначе цена Яндекса.
 */
final class ResolveDisplayedDeliveryCostTest extends TestCase
{
    use RefreshDatabase;
    use TogglesDeliveryPriceRangesModule;

    protected function setUp(): void
    {
        parent::setUp();
        // Тесты написаны до тумблера (Task 10): модуль включён, поведение прежнее.
        $this->setDeliveryPriceRangesModule(true);
    }

    private function info(string $type, array $ranges, ?int $freeDeliveryFrom = null): DeliveryInfoData
    {
        return new DeliveryInfoData(
            slots: [],
            minOrderAmount: 0,
            freeDeliveryFrom: $freeDeliveryFrom,
            deliveryPrice: 200,
            deliveryType: $type,
            deliveryPriceRanges: $ranges,
        );
    }

    private function resolve(DeliveryInfoData $info, float $subtotal): float
    {
        $store = Store::factory()->create(['latitude' => 55.75, 'longitude' => 37.61]);
        $address = Address::factory()->create(['latitude' => 55.7558, 'longitude' => 37.6173, 'house_number' => '1']);

        return (new DeliveryService(new FakeDeliveryClient, new FakeYandexGoClient))
            ->resolveDisplayedDeliveryCost($store, $address, $info, $subtotal);
    }

    public function test_own_zone_uses_range_price(): void
    {
        $info = $this->info('own', [
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], 3000);

        $this->assertSame(200.0, $this->resolve($info, 999));
        $this->assertSame(100.0, $this->resolve($info, 1500));
        $this->assertSame(0.0, $this->resolve($info, 3000));
    }

    public function test_yandex_zone_is_free_from_free_range_otherwise_yandex_price(): void
    {
        // Порог в DTO пуст: бесплатность берётся из ступени зоны
        $info = $this->info('yandex', [['from_amount' => 3000, 'price' => 0]], null);

        $this->assertSame(0.0, $this->resolve($info, 3000));
        // FakeYandexGoClient: 507.52 → priceRounded() = 508
        $this->assertSame(508.0, $this->resolve($info, 2999));
    }

    public function test_yandex_zone_without_ranges_keeps_free_threshold(): void
    {
        $info = $this->info('yandex', [], 2000);

        $this->assertSame(0.0, $this->resolve($info, 2000));
        $this->assertSame(508.0, $this->resolve($info, 1999));
    }
}
