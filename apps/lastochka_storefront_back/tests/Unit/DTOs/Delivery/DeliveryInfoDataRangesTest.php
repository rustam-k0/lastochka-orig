<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * MID-280: ступени стоимости доставки зоны из ответа slots-api /available
 * (ключ shipping_price_ranges). Нет ключа (пакет slots-api ещё не задеплоен):
 * [] и лесенка из shipping_free_from, как прежний порог.
 */
final class DeliveryInfoDataRangesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Тесты написаны до тумблера (Task 10): модуль включён, поведение прежнее.
        // Напрямую через кеш — файл без RefreshDatabase, storefront_settings не мигрирована.
        Cache::put(ModuleConfigService::CACHE_KEY_DELIVERY_PRICE_RANGES, true, now()->addMinutes(10));
    }

    public function test_from_api_response_parses_normalized_ranges(): void
    {
        $dto = DeliveryInfoData::fromApiResponse([
            'min_order_price' => 500,
            'shipping_price' => 200,
            'shipping_free_from' => 3000,
            'shipping_price_ranges' => [
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => 1000, 'price' => 100],
            ],
        ]);

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $dto->deliveryPriceRanges);
        $this->assertSame(200, $dto->ladder()->priceFor(999));
        $this->assertSame(100, $dto->ladder()->priceFor(1000));
        $this->assertSame(0, $dto->ladder()->priceFor(3000));
    }

    public function test_without_ranges_key_ladder_uses_free_delivery_from(): void
    {
        $dto = DeliveryInfoData::fromApiResponse([
            'min_order_price' => 500,
            'shipping_price' => 200,
            'shipping_free_from' => 2000,
        ]);

        $this->assertSame([], $dto->deliveryPriceRanges);
        $this->assertSame(200, $dto->ladder()->priceFor(1999.99));
        $this->assertSame(0, $dto->ladder()->priceFor(2000));
    }

    public function test_broken_ranges_are_dropped(): void
    {
        $dto = DeliveryInfoData::fromApiResponse([
            'min_order_price' => 500,
            'shipping_price' => 200,
            'shipping_price_ranges' => [['from_amount' => 'x', 'price' => 100]],
        ]);

        $this->assertSame([], $dto->deliveryPriceRanges);
        $this->assertSame(200, $dto->ladder()->priceFor(100000));
    }

    public function test_constructor_defaults_ranges_to_empty(): void
    {
        $dto = new DeliveryInfoData(
            slots: [],
            minOrderAmount: 500,
            freeDeliveryFrom: 1500,
            deliveryPrice: 200,
        );

        $this->assertSame([], $dto->deliveryPriceRanges);
        $this->assertSame(0, $dto->ladder()->priceFor(1500));
    }
}
