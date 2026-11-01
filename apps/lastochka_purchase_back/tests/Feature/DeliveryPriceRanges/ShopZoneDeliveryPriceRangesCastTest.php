<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\ShopZone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MID-280: ступени зоны читаются моделью purchase_back массивом (их отдаёт slots-api).
 */
class ShopZoneDeliveryPriceRangesCastTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ranges_are_read_as_array(): void
    {
        $zone = ShopZone::factory()->create(['delivery_price' => 200, 'min_order_price' => 500]);
        DB::table('shop_zones')->where('id', $zone->id)->update([
            'delivery_price_ranges' => json_encode([
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ]),
        ]);

        // MySQL JSON переставляет ключи объекта: сравнение без учёта порядка ключей
        $this->assertEquals([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], ShopZone::find($zone->id)->delivery_price_ranges);
    }

    public function test_zone_without_ranges_reads_null(): void
    {
        $zone = ShopZone::factory()->create(['delivery_price' => 200, 'min_order_price' => 500]);

        $this->assertNull(ShopZone::find($zone->id)->delivery_price_ranges);
    }
}
