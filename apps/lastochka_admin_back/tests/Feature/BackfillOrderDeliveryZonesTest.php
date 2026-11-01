<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientOrderInfo;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\ZoneCoordinate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillOrderDeliveryZonesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_backfill_fills_zone_for_historical_orders(): void
    {
        // Shop-модель без HasFactory-трейта в этом репо → Shop::create() вместо factory()
        // (как в tests/Feature/OrderDeliveryZoneSyncTest.php, Task 3).
        $shop = Shop::create([
            'title' => 'Тестовый',
            'code' => 'test-' . uniqid(),
            'type_id' => 1,
            'status_id' => 1,
        ]);
        $zone = ShopZone::create([
            'shop_id' => $shop->id, 'name' => 'Зона', 'delivery_price' => 100,
            'min_order_price' => 0, // NOT NULL без дефолта
        ]);
        foreach ([[55.0, 37.0], [55.0, 37.1], [55.1, 37.1], [55.1, 37.0]] as $i => [$lat, $lng]) {
            ZoneCoordinate::create([
                'shop_zone_id' => $zone->id, 'latitude' => $lat, 'longitude' => $lng, 'position' => $i,
            ]);
        }

        // orders.client_id/uuid/payment_type_id — NOT NULL, поэтому дополняем набор из брифа.
        $clientId = Client::create(['password' => 'x'])->id;
        $order = Order::create([
            'shop_id' => $shop->id, 'status_id' => 5, 'type_id' => 2, 'amount' => 900,
            'client_id' => $clientId, 'payment_type_id' => 1, 'uuid' => (string) Str::uuid(),
        ]);
        ClientOrderInfo::create([
            'order_id' => $order->id, 'name' => 'Тест',
            'latitude' => 55.05, 'longitude' => 37.05, 'address' => 'Адрес',
        ]);

        $this->artisan('orders:backfill-delivery-zones')->assertExitCode(0);

        $this->assertSame($zone->id, (int) $order->fresh()->zone_id);
    }
}
