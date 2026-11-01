<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * admin_orders_paginate: поля collector/couriers резолвятся per-order (N+1).
 * Тест фиксирует корректный результат и ограничивает число запросов —
 * после eager-load оно не должно расти линейно с числом заказов.
 */
class OrderListNPlusOneTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): int
    {
        return DB::table('shops')->insertGetId([
            'title' => 'N1 shop ' . uniqid(),
            'id_1c' => random_int(900000, 999999),
            'default' => 0, 'slot_max_orders' => 10, 'slot_time' => 60,
            'is_popular' => 0, 'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeClient(): int
    {
        return DB::table('clients')->insertGetId([
            'is_authorized' => 1, 'newsletter_subscribed' => 0,
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $marker): int
    {
        return DB::table('users')->insertGetId([
            'name' => $marker, 'email' => uniqid('n1_') . '@test.local',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeStaffToken(): string
    {
        $userId = $this->makeUser('Admin');
        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));

        return $user->createToken('test')->plainTextToken;
    }

    /** Заказ с назначенным сборщиком (assembly) и курьером (delivery_order). */
    private function makeOrderWithStaff(int $collectorId, int $courierId): int
    {
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop(),
            'client_id' => $this->makeClient(),
            'status_id' => Status::ORDER_ASSEMBLED,
            'type_id' => Type::RECEIVE_DELIVERY,
            'payment_type_id' => 1,
            'uuid' => 'n1-' . uniqid(),
        ]);

        DB::table('assemblies')->insert([
            'order_id' => $order->id, 'user_id' => $collectorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $deliveryId = DB::table('deliveries')->insertGetId([
            'user_id' => $courierId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('delivery_orders')->insert([
            'order_id' => $order->id, 'delivery_id' => $deliveryId, 'status_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $order->id;
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    public function test_collector_and_couriers_resolve_without_per_order_growth(): void
    {
        $token = $this->makeStaffToken();

        $expected = [];
        for ($i = 0; $i < 10; $i++) {
            $collector = $this->makeUser('Collector ' . $i);
            $courier = $this->makeUser('Courier ' . $i);
            $orderId = $this->makeOrderWithStaff($collector, $courier);
            $expected[$orderId] = ['collector' => $collector, 'courier' => $courier];
        }

        DB::enableQueryLog();
        $resp = $this->gql($token, '{ admin_orders_paginate(first: 100) { data { id collector { id } couriers { id } } } }');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $resp->assertJsonMissingPath('errors');

        // Корректность: collector и courier у каждого заказа те, что назначили.
        $rows = collect($resp->json('data.admin_orders_paginate.data'))->keyBy('id');
        foreach ($expected as $orderId => $exp) {
            $row = $rows->get($orderId);
            $this->assertNotNull($row, "Заказ {$orderId} в выдаче");
            $this->assertSame($exp['collector'], (int) $row['collector']['id']);
            $this->assertSame([$exp['courier']], collect($row['couriers'])->pluck('id')->map(fn ($x) => (int) $x)->all());
        }

        // Число запросов НЕ растёт линейно с числом заказов: при N+1 на 10
        // заказов было бы ~100 (было 40 на 4), при eager-load — фиксированный
        // оверхед (~18-22) независимо от числа заказов.
        $this->assertLessThanOrEqual(25, $queryCount, "SQL-запросов на 10 заказов: {$queryCount} (N+1?)");
    }
}
