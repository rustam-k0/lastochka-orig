<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientOrderInfo;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\ZoneCoordinate;
use App\Services\OrderDeliveryZoneSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderDeliveryZoneSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sync_recomputes_zone_after_shop_change(): void
    {
        // orders.client_id — NOT NULL + FK на clients.id, поэтому создаём клиента.
        $clientId = Client::create(['password' => 'x'])->id;

        // Shop-модель без HasFactory-трейта → создаём через create() (по примечанию брифа).
        $shopA = Shop::create(['title' => 'A', 'code' => 'shop-a-' . uniqid(), 'type_id' => 1, 'status_id' => 1]);
        $shopB = Shop::create(['title' => 'B', 'code' => 'shop-b-' . uniqid(), 'type_id' => 1, 'status_id' => 1]);

        $zoneB = ShopZone::create([
            'shop_id' => $shopB->id, 'name' => 'Зона B', 'delivery_price' => 100, 'min_order_price' => 0,
        ]);
        foreach ([[55.0, 37.0], [55.0, 37.1], [55.1, 37.1], [55.1, 37.0]] as $i => [$lat, $lng]) {
            ZoneCoordinate::create([
                'shop_zone_id' => $zoneB->id, 'latitude' => $lat, 'longitude' => $lng, 'position' => $i,
            ]);
        }

        // orders.uuid/type_id — NOT NULL, но не входят в $fillable Order (delivery_back
        // не мутирует эти поля через мутации) → forceCreate обходит guard только в тесте.
        $order = Order::forceCreate([
            'shop_id' => $shopA->id,
            'status_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'uuid' => (string) Str::uuid(),
        ]);
        ClientOrderInfo::create([
            'order_id' => $order->id, 'name' => 'Тест', 'latitude' => 55.05, 'longitude' => 37.05, 'address' => 'Адрес',
        ]);

        // у магазина A зон нет — зона не определена
        (new OrderDeliveryZoneSync())->sync($order);
        $this->assertNull($order->fresh()->zone_id);

        // смена магазина на B — зона должна определиться
        $order->update(['shop_id' => $shopB->id]);
        (new OrderDeliveryZoneSync())->sync($order->fresh());
        $this->assertSame($zoneB->id, (int) $order->fresh()->zone_id);
    }
}
