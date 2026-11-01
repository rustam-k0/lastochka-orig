<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\ShopZone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderDeliveryZoneGraphqlTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(string $title): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => $title,
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeClient(): int
    {
        return \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeStaffToken(): string
    {
        // Роль admin, а не кастомная: global scope onlyAdmin пропускает кастомные
        // роли только при включённом role_access_matrix_enabled — тест не должен
        // зависеть от состояния этого флага в общей тестовой БД.
        $role = Role::findOrCreate(Role::ADMIN, 'api');

        $userId = \DB::table('users')->insertGetId([
            'name' => 'Test',
            'email' => uniqid('dz_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return $user->createToken('test')->plainTextToken;
    }

    private function makeZone(int $shopId, string $name, array $points): ShopZone
    {
        $zone = ShopZone::create([
            'shop_id' => $shopId,
            'delivery_price' => 0,
            'min_order_price' => 0,
        ]);
        $zone->name = $name;
        $zone->save();

        foreach ($points as $position => $point) {
            [$lat, $lng] = $point;
            $zone->coordinates()->create([
                'latitude' => $lat,
                'longitude' => $lng,
                'position' => $position,
            ]);
        }

        return $zone;
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    public function test_delivery_zone_returned_when_coordinates_fall_inside_polygon(): void
    {
        $shopId = $this->makeShop('DZ test shop');
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $this->makeZone($shopId, 'Северная зона', [
            ['56.00', '92.80'],
            ['56.00', '92.90'],
            ['56.10', '92.90'],
            ['56.10', '92.80'],
        ]);

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]);
        $order->clientInfo()->create([
            'name' => 'Test Client',
            'latitude' => '56.05',
            'longitude' => '92.85',
        ]);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id delivery_zone { title } } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertSame('Северная зона', $resp->json('data.admin_orders_item.delivery_zone.title'));
    }

    public function test_delivery_zone_null_when_coordinates_outside_all_zones(): void
    {
        $shopId = $this->makeShop('DZ test shop outside');
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $this->makeZone($shopId, 'Северная зона', [
            ['56.00', '92.80'],
            ['56.00', '92.90'],
            ['56.10', '92.90'],
            ['56.10', '92.80'],
        ]);

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]);
        $order->clientInfo()->create([
            'name' => 'Test Client',
            'latitude' => '10.00',
            'longitude' => '10.00',
        ]);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id delivery_zone { title } } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertNull($resp->json('data.admin_orders_item.delivery_zone'));
    }

    public function test_delivery_zone_null_when_no_coordinates(): void
    {
        $shopId = $this->makeShop('DZ test shop pickup');
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'type_id' => 1,
            'payment_type_id' => 1,
        ]);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id delivery_zone { title } } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertNull($resp->json('data.admin_orders_item.delivery_zone'));
    }

    public function test_delivery_zone_null_when_order_has_no_shop_assigned(): void
    {
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => null,
            'client_id' => $clientId,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]);
        $order->clientInfo()->create([
            'name' => 'Test Client',
            'latitude' => '56.05',
            'longitude' => '92.85',
        ]);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id delivery_zone { title } } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertNull($resp->json('data.admin_orders_item.delivery_zone'));
    }

    public function test_delivery_zone_returns_lowest_id_when_point_matches_multiple_zones(): void
    {
        $shopId = $this->makeShop('DZ test shop duplicate zones');
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $polygon = [
            ['56.00', '92.80'],
            ['56.00', '92.90'],
            ['56.10', '92.90'],
            ['56.10', '92.80'],
        ];

        $firstZone = $this->makeZone($shopId, 'Первая зона', $polygon);
        $this->makeZone($shopId, 'Вторая зона', $polygon);

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]);
        $order->clientInfo()->create([
            'name' => 'Test Client',
            'latitude' => '56.05',
            'longitude' => '92.85',
        ]);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id delivery_zone { title } } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertSame($firstZone->name, $resp->json('data.admin_orders_item.delivery_zone.title'));
    }
}
