<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Client;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\Status;
use App\Models\Type;
use App\Services\Pricing\DeliveryPriceLadder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MID-280: хранение ступеней стоимости доставки зоны и снимка условий доставки в заказе.
 */
class DeliveryPriceRangesStorageTest extends TestCase
{
    use DatabaseTransactions;

    private const RANGES_MIGRATION = '2026_09_22_100000_add_delivery_price_ranges_to_shop_zones_table.php';

    private function zoneRow(array $extra = []): int
    {
        return DB::table('shop_zones')->insertGetId(array_merge([
            'shop_id' => Shop::firstOrFail()->id,
            'name' => 'MID-280 ' . uniqid(),
            'delivery_price' => 200,
            'min_order_price' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /**
     * MySQL JSON хранит ключи объекта в своём порядке (price раньше from_amount):
     * сырое значение читается через normalize(), как во всём коде фичи.
     */
    private function rangesOf(int $zoneId): ?array
    {
        $raw = DB::table('shop_zones')->where('id', $zoneId)->value('delivery_price_ranges');

        return $raw === null ? null : DeliveryPriceLadder::normalize($raw);
    }

    private function runRangesMigration(): void
    {
        $migration = require database_path('migrations/' . self::RANGES_MIGRATION);
        $migration->up();
    }

    public function test_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('shop_zones', 'delivery_price_ranges'));
        $this->assertTrue(Schema::hasColumn('orders', 'delivery_price_snapshot'));
    }

    public function test_migration_moves_free_delivery_from_into_single_free_range(): void
    {
        $withThreshold = $this->zoneRow(['free_delivery_from' => 1000]);
        $zeroThreshold = $this->zoneRow(['free_delivery_from' => 0]);
        $withoutThreshold = $this->zoneRow(['free_delivery_from' => null]);
        $deleted = $this->zoneRow(['free_delivery_from' => 500, 'deleted_at' => now()]);

        $this->runRangesMigration();

        $this->assertSame([['from_amount' => 1000, 'price' => 0]], $this->rangesOf($withThreshold));
        $this->assertSame([['from_amount' => 0, 'price' => 0]], $this->rangesOf($zeroThreshold));
        $this->assertNull($this->rangesOf($withoutThreshold));
        $this->assertSame([['from_amount' => 500, 'price' => 0]], $this->rangesOf($deleted));
    }

    public function test_migration_keeps_existing_ranges(): void
    {
        $ranges = [['from_amount' => 1000, 'price' => 100], ['from_amount' => 3000, 'price' => 0]];
        $zoneId = $this->zoneRow(['free_delivery_from' => 3000, 'delivery_price_ranges' => json_encode($ranges)]);

        $this->runRangesMigration();
        $this->runRangesMigration();

        $this->assertSame($ranges, $this->rangesOf($zoneId));
    }

    public function test_zone_casts_ranges_and_lists_them_normalized(): void
    {
        $zone = ShopZone::create([
            'shop_id' => Shop::firstOrFail()->id,
            'name' => 'MID-280 cast ' . uniqid(),
            'delivery_price' => 200,
            'min_order_price' => 500,
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => 1000, 'price' => 100],
            ],
        ]);

        $fresh = $zone->fresh();

        $this->assertIsArray($fresh->delivery_price_ranges);
        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $fresh->deliveryPriceRangesList());
    }

    public function test_zone_without_ranges_lists_empty_array(): void
    {
        $zone = ShopZone::find($this->zoneRow(['free_delivery_from' => null]));

        $this->assertNull($zone->delivery_price_ranges);
        $this->assertSame([], $zone->deliveryPriceRangesList());
    }

    public function test_order_casts_delivery_price_snapshot(): void
    {
        $client = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'is_authorized' => true,
        ]);
        $snapshot = [
            'base_price' => 200,
            'ranges' => [['from_amount' => 1000, 'price' => 100], ['from_amount' => 3000, 'price' => 0]],
            'checkout_price' => 100,
        ];

        $order = Order::withoutEvents(fn () => Order::create([
            'uuid' => 'mid280-storage-' . uniqid(),
            'client_id' => $client->id,
            'shop_id' => Shop::firstOrFail()->id,
            'type_id' => Type::DELIVERY_COURIER,
            'status_id' => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount' => 1600,
            'origin_amount' => 1500,
            'paid' => false,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'delivery_price' => 100,
            'delivery_price_snapshot' => $snapshot,
        ]));

        // MySQL JSON нормализует порядок ключей объекта: сравнение без учёта порядка
        $this->assertEquals($snapshot, $order->fresh()->delivery_price_snapshot);
    }
}
