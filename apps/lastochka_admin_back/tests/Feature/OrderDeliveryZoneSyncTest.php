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

    private ?int $clientId = null;

    // orders.client_id — NOT NULL + FK на clients.id, поэтому создаём клиента
    // один раз и переиспользуем (минимальные значения по примечанию брифа).
    private function clientId(): int
    {
        return $this->clientId ??= Client::create(['password' => 'x'])->id;
    }

    private function makeShopWithZone(): array
    {
        // Shop-модель без HasFactory-трейта → используем create() по примечанию брифа.
        $shop = Shop::create([
            'title' => 'Тестовый',
            'code' => 'test-' . uniqid(),
            'type_id' => 1,
            'status_id' => 1,
        ]);

        $zone = ShopZone::create([
            'shop_id' => $shop->id,
            'name' => 'Тестовая зона',
            'delivery_price' => 100,
            'min_order_price' => 0, // NOT NULL без дефолта
        ]);
        foreach ([[55.0, 37.0], [55.0, 37.1], [55.1, 37.1], [55.1, 37.0]] as $i => [$lat, $lng]) {
            ZoneCoordinate::create([
                'shop_zone_id' => $zone->id,
                'latitude' => $lat,
                'longitude' => $lng,
                'position' => $i,
            ]);
        }

        return [$shop, $zone];
    }

    private function makeOrder(Shop $shop, ?float $lat, ?float $lng): Order
    {
        $order = Order::create([
            'shop_id' => $shop->id,
            'status_id' => 1,
            'type_id' => 2,
            'amount' => 1000,
            'client_id' => $this->clientId(),
            'payment_type_id' => 1,
            'uuid' => (string) Str::uuid(),
        ]);
        if ($lat !== null) {
            ClientOrderInfo::create([
                'order_id' => $order->id,
                'name' => 'Тест',
                'latitude' => $lat,
                'longitude' => $lng,
                'address' => 'Тестовый адрес',
            ]);
        }

        return $order;
    }

    public function test_sync_sets_zone_for_order_inside_polygon(): void
    {
        [$shop, $zone] = $this->makeShopWithZone();
        $order = $this->makeOrder($shop, 55.05, 37.05);

        (new OrderDeliveryZoneSync())->sync($order);

        $this->assertSame($zone->id, (int) $order->fresh()->zone_id);
    }

    public function test_sync_leaves_null_when_point_outside_all_zones(): void
    {
        [$shop] = $this->makeShopWithZone();
        $order = $this->makeOrder($shop, 59.0, 30.0);

        (new OrderDeliveryZoneSync())->sync($order);

        $this->assertNull($order->fresh()->zone_id);
    }

    public function test_sync_cascades_zone_to_suborders(): void
    {
        [$shop, $zone] = $this->makeShopWithZone();
        $parent = $this->makeOrder($shop, 55.05, 37.05);
        $child = Order::create([
            'shop_id' => $shop->id,
            'status_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'parent_order_id' => $parent->id,
            'sub_number' => 1,
            'client_id' => $this->clientId(),
            'payment_type_id' => 1,
            'uuid' => (string) Str::uuid(),
        ]);

        (new OrderDeliveryZoneSync())->sync($parent);

        $this->assertSame($zone->id, (int) $child->fresh()->zone_id);
    }

    public function test_sync_skips_suborder_itself(): void
    {
        [$shop] = $this->makeShopWithZone();
        $parent = $this->makeOrder($shop, 55.05, 37.05);
        $child = Order::create([
            'shop_id' => $shop->id,
            'status_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'parent_order_id' => $parent->id,
            'sub_number' => 1,
            'client_id' => $this->clientId(),
            'payment_type_id' => 1,
            'uuid' => (string) Str::uuid(),
        ]);

        (new OrderDeliveryZoneSync())->sync($child);

        $this->assertNull($child->fresh()->zone_id);
    }
}
