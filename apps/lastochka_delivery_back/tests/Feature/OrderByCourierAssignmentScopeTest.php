<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Модуль courier_assignment: курьер видит только назначенные ему заказы
 * (Platrum 12183 / MID-151). При выключенном модуле и для остальных ролей
 * scope byCourierAssignment — no-op.
 */
class OrderByCourierAssignmentScopeTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $courier;
    private User $otherCourier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'CAssign scope shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->courier = $this->makeStaff('courier');
        $this->otherCourier = $this->makeStaff('courier');
    }

    private function makeStaff(string $roleName): User
    {
        $userId = \DB::table('users')->insertGetId([
            'name' => ucfirst($roleName), 'surname' => 'Тестовый ' . uniqid(),
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::findOrFail($userId);
        $user->assignRole(Role::findOrCreate($roleName, 'api'));

        \DB::table('shifts')->insert([
            'user_id' => $user->id, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function insertOrder(array $attrs = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'bcas-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function assignToCourier(int $orderId, int $courierId, array $deliveryAttrs = [], array $deliveryOrderAttrs = []): void
    {
        $deliveryId = \DB::table('deliveries')->insertGetId(array_merge([
            'user_id' => $courierId, 'created_at' => now(), 'updated_at' => now(),
        ], $deliveryAttrs));

        \DB::table('delivery_orders')->insert(array_merge([
            'delivery_id' => $deliveryId, 'order_id' => $orderId,
            'status_id' => Status::DELIVERY_WAIT_TO_START, 'priority' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $deliveryOrderAttrs));
    }

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** Хелпер: id заказов, видимых через scope (изоляция от чужих данных по whereIn). */
    private function visibleIds(array $orderIds): array
    {
        return Order::query()
            ->byCourierAssignment()
            ->whereIn('id', $orderIds)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /** @test */
    public function courier_sees_only_assigned_orders_when_module_enabled()
    {
        $this->setModule(true);
        $mineId = $this->insertOrder();
        $foreignId = $this->insertOrder();
        $unassignedId = $this->insertOrder();
        $this->assignToCourier($mineId, $this->courier->id);
        $this->assignToCourier($foreignId, $this->otherCourier->id);

        Sanctum::actingAs($this->courier, [], 'api');
        $ids = $this->visibleIds([$mineId, $foreignId, $unassignedId]);

        $this->assertContains($mineId, $ids);
        $this->assertNotContains($foreignId, $ids);
        $this->assertNotContains($unassignedId, $ids);
    }

    /** @test */
    public function courier_sees_all_orders_when_module_disabled()
    {
        $this->setModule(false);
        $mineId = $this->insertOrder();
        $unassignedId = $this->insertOrder();
        $this->assignToCourier($mineId, $this->courier->id);

        Sanctum::actingAs($this->courier, [], 'api');
        $ids = $this->visibleIds([$mineId, $unassignedId]);

        $this->assertContains($mineId, $ids);
        $this->assertContains($unassignedId, $ids);
    }

    /** @test */
    public function courier_sees_assigned_order_even_when_delivery_finished()
    {
        $this->setModule(true);
        $mineId = $this->insertOrder(['status_id' => Status::ORDER_FINISHED]);
        $this->assignToCourier(
            $mineId,
            $this->courier->id,
            ['started_at' => now(), 'finished_at' => now()],
            ['status_id' => Status::DELIVERY_FINISHED, 'started_at' => now(), 'finished_at' => now()]
        );

        Sanctum::actingAs($this->courier, [], 'api');
        $ids = $this->visibleIds([$mineId]);

        $this->assertContains($mineId, $ids);
    }

    /** @test */
    public function cancelled_assignment_hides_order_and_reassignment_shows_it_to_new_courier()
    {
        // Отмена рейса = soft-delete delivery_order: заказ пропадает у старого
        // курьера; после переназначения (например админом) виден новому.
        $this->setModule(true);
        $orderId = $this->insertOrder();
        $this->assignToCourier($orderId, $this->courier->id, [], ['deleted_at' => now()]);
        $this->assignToCourier($orderId, $this->otherCourier->id);

        Sanctum::actingAs($this->courier, [], 'api');
        $this->assertNotContains($orderId, $this->visibleIds([$orderId]));

        Sanctum::actingAs($this->otherCourier, [], 'api');
        $this->assertContains($orderId, $this->visibleIds([$orderId]));
    }

    /** @test */
    public function collector_is_not_affected_by_module()
    {
        $this->setModule(true);
        $collector = $this->makeStaff('collector');
        $unassignedId = $this->insertOrder();

        Sanctum::actingAs($collector, [], 'api');
        $ids = $this->visibleIds([$unassignedId]);

        $this->assertContains($unassignedId, $ids);
    }

    /** @test */
    public function single_order_query_hides_foreign_order_from_courier()
    {
        $this->setModule(true);
        $foreignId = $this->insertOrder();
        $this->assignToCourier($foreignId, $this->otherCourier->id);

        Sanctum::actingAs($this->courier, [], 'api');
        $response = $this->graphQL('query Order($id: Int) { order(id: $id) { id } }', ['id' => $foreignId]);

        $this->assertNull($response->json('data.order'));
    }

    /** @test */
    public function single_order_query_shows_own_order_to_courier()
    {
        $this->setModule(true);
        $mineId = $this->insertOrder();
        $this->assignToCourier($mineId, $this->courier->id);

        Sanctum::actingAs($this->courier, [], 'api');
        $response = $this->graphQL('query Order($id: Int) { order(id: $id) { id } }', ['id' => $mineId]);

        $this->assertSame($mineId, (int) $response->json('data.order.id'));
    }

    /** @test */
    public function orders_paginate_applies_assignment_filter_for_courier()
    {
        $this->setModule(true);
        $mineId = $this->insertOrder();
        $foreignId = $this->insertOrder();
        $this->assignToCourier($mineId, $this->courier->id);
        $this->assignToCourier($foreignId, $this->otherCourier->id);

        Sanctum::actingAs($this->courier, [], 'api');
        $response = $this->graphQL(
            'query Orders($status: Int) { orders_paginate(status: $status, first: 100) { data { id } } }',
            ['status' => Status::ORDER_ASSEMBLED]
        );

        $ids = array_map('intval', array_column($response->json('data.orders_paginate.data') ?? [], 'id'));

        $this->assertContains($mineId, $ids);
        $this->assertNotContains($foreignId, $ids);
    }
}
