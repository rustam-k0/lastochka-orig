<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Фильтр по назначенным курьерам в списке заказов АП.
 * Код 0 — виртуальный «Без курьера».
 * Спека: docs/superpowers/specs/2026-07-30-orders-courier-filter-slots-export-design.md
 */
class OrderCourierFilterTest extends TestCase
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
        $userId = \DB::table('users')->insertGetId([
            'name' => 'Test',
            'email' => uniqid('ocf_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));

        return $user->createToken('test')->plainTextToken;
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    private function makeOrder(string $marker, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'shop_id' => $this->makeShop('OCF shop ' . $marker),
            'client_id' => $this->makeClient(),
            'status_id' => Status::ORDER_ASSEMBLED,
            'type_id' => Type::RECEIVE_DELIVERY,
            'payment_type_id' => 1,
            'uuid' => 'ocf-' . $marker . '-' . uniqid(),
        ], $attrs));
    }

    private function makeCourier(string $marker): int
    {
        return \DB::table('users')->insertGetId([
            'name' => 'Courier ' . $marker,
            'email' => uniqid('ocfc_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assign(int $orderId, int $courierId, bool $deleted = false): void
    {
        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $courierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('delivery_orders')->insert([
            'delivery_id' => $deliveryId,
            'order_id' => $orderId,
            'status_id' => 1,
            'priority' => 0,
            'deleted_at' => $deleted ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * first: 100 — это максимум, разрешённый lighthouse.php (max_count),
     * при 101 и больше Lighthouse вернёт ошибку вместо данных.
     *
     * @return array<int>
     */
    private function idsFor(string $token, string $couriersArg): array
    {
        $resp = $this->gql($token, '{ admin_orders_paginate(couriers: ' . $couriersArg . ', first: 100) { data { id } } }');
        $resp->assertJsonMissingPath('errors');

        return collect($resp->json('data.admin_orders_paginate.data'))->pluck('id')->all();
    }

    public function test_filters_by_single_courier(): void
    {
        $token = $this->makeStaffToken();
        $courierA = $this->makeCourier('a1');
        $courierB = $this->makeCourier('b1');
        $orderA = $this->makeOrder('a1');
        $orderB = $this->makeOrder('b1');
        $this->assign($orderA->id, $courierA);
        $this->assign($orderB->id, $courierB);

        $ids = $this->idsFor($token, '[' . $courierA . ']');

        $this->assertContains($orderA->id, $ids);
        $this->assertNotContains($orderB->id, $ids);
    }

    public function test_filters_by_several_couriers(): void
    {
        $token = $this->makeStaffToken();
        $courierA = $this->makeCourier('a2');
        $courierB = $this->makeCourier('b2');
        $courierC = $this->makeCourier('c2');
        $orderA = $this->makeOrder('a2');
        $orderB = $this->makeOrder('b2');
        $orderC = $this->makeOrder('c2');
        $this->assign($orderA->id, $courierA);
        $this->assign($orderB->id, $courierB);
        $this->assign($orderC->id, $courierC);

        $ids = $this->idsFor($token, '[' . $courierA . ', ' . $courierB . ']');

        $this->assertContains($orderA->id, $ids);
        $this->assertContains($orderB->id, $ids);
        $this->assertNotContains($orderC->id, $ids);
    }

    public function test_zero_code_returns_orders_without_courier(): void
    {
        $token = $this->makeStaffToken();
        $courier = $this->makeCourier('a3');
        $assigned = $this->makeOrder('a3');
        $bare = $this->makeOrder('b3');
        $this->assign($assigned->id, $courier);

        $ids = $this->idsFor($token, '[0]');

        $this->assertContains($bare->id, $ids);
        $this->assertNotContains($assigned->id, $ids);
    }

    public function test_courier_and_zero_code_compose_via_or(): void
    {
        $token = $this->makeStaffToken();
        $courierA = $this->makeCourier('a4');
        $courierB = $this->makeCourier('b4');
        $orderA = $this->makeOrder('a4');
        $orderB = $this->makeOrder('b4');
        $bare = $this->makeOrder('c4');
        $this->assign($orderA->id, $courierA);
        $this->assign($orderB->id, $courierB);

        $ids = $this->idsFor($token, '[0, ' . $courierA . ']');

        $this->assertContains($orderA->id, $ids);
        $this->assertContains($bare->id, $ids);
        $this->assertNotContains($orderB->id, $ids);
    }

    public function test_child_order_assignment_matches_parent(): void
    {
        $token = $this->makeStaffToken();
        $courier = $this->makeCourier('a5');
        $parent = $this->makeOrder('p5');
        $child = $this->makeOrder('c5', [
            'parent_order_id' => $parent->id,
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
        ]);
        $this->assign($child->id, $courier);

        $ids = $this->idsFor($token, '[' . $courier . ']');

        $this->assertContains($parent->id, $ids);
    }

    public function test_soft_deleted_assignment_is_ignored(): void
    {
        $token = $this->makeStaffToken();
        $courier = $this->makeCourier('a6');
        $order = $this->makeOrder('a6');
        $this->assign($order->id, $courier, true);

        $byCourier = $this->idsFor($token, '[' . $courier . ']');
        $withoutCourier = $this->idsFor($token, '[0]');

        $this->assertNotContains($order->id, $byCourier);
        $this->assertContains($order->id, $withoutCourier);
    }
}
