<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\ZoneCoordinate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MID-280: пакет cron/slots-api, установленный в purchase_back, отдаёт витрине ступени зоны.
 * Страж версии пакета в composer.lock: красный = в vendor пакет без MID-280
 * (выполнить `composer update cron/slots-api` после merge пакета в main).
 */
class SlotsApiDeliveryPriceRangesTest extends TestCase
{
    use DatabaseTransactions;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();

        $zone = ShopZone::factory()->create([
            'shop_id' => $this->shop->id,
            'delivery_price' => 200,
            'min_order_price' => 500,
        ]);
        DB::table('shop_zones')->where('id', $zone->id)->update([
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => json_encode([
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => 1000, 'price' => 100],
            ]),
        ]);

        foreach ([[55.5, 37.3, 1], [55.5, 37.9, 2], [56.0, 37.9, 3], [56.0, 37.3, 4]] as [$latitude, $longitude, $position]) {
            ZoneCoordinate::factory()->create([
                'shop_zone_id' => $zone->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'position' => $position,
            ]);
        }
    }

    public function test_available_returns_shipping_price_ranges(): void
    {
        $response = $this->postJson('/api/v1/slots/available', [
            'code' => $this->shop->code,
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'date' => now()->addDay()->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $response->json('shipping_price_ranges'));
        $this->assertEquals(3000, $response->json('shipping_free_from'));
        $this->assertEquals(200, $response->json('shipping_price'));
    }

    public function test_shops_returns_delivery_price_ranges(): void
    {
        $response = $this->postJson('/api/v1/slots/shops', [
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response->assertStatus(200);

        $shop = collect($response->json('shops'))->firstWhere('id', $this->shop->id);
        $this->assertNotNull($shop);
        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $shop['delivery_price_ranges'] ?? null);
        $this->assertEquals(3000, $shop['free_delivery_from']);
    }
}
