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
 * Фильтр «Назначен курьер» (виртуальный статус 8) в списке заказов АП.
 * Спека: mendev_delivery_back/docs/superpowers/specs/2026-07-18-courier-assigned-status-design.md
 */
class OrderCourierAssignedStatusFilterTest extends TestCase
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
        $role = Role::findOrCreate(Role::ADMIN, 'api');

        $userId = \DB::table('users')->insertGetId([
            'name' => 'Test',
            'email' => uniqid('ocas_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return $user->createToken('test')->plainTextToken;
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    private function makeOrder(int $statusId, string $marker, array $attrs = []): Order
    {
        $shopId = $this->makeShop('OCAS shop ' . $marker);
        $clientId = $this->makeClient();

        return Order::factory()->create(array_merge([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'status_id' => $statusId,
            'type_id' => Type::RECEIVE_DELIVERY,
            'payment_type_id' => 1,
            'uuid' => 'ocas-' . $marker . '-' . uniqid(),
        ], $attrs));
    }

    private function makeCourierAssignment(int $orderId, bool $softDeleted = false): void
    {
        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Courier',
            'email' => uniqid('ocasc_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $courierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('delivery_orders')->insert([
            'delivery_id' => $deliveryId,
            'order_id' => $orderId,
            'status_id' => 1, // WAIT_TO_START; delivery-констант в purchase_back нет, EXISTS на него не смотрит
            'priority' => 0,
            'deleted_at' => $softDeleted ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setCourierAssignmentModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function test_courier_assigned_filter_returns_only_assembled_with_assignment(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned1');
        $this->makeCourierAssignment($assigned->id);
        $bare = $this->makeOrder(Status::ORDER_ASSEMBLED, 'bare1');
        $inDelivery = $this->makeOrder(Status::ORDER_IN_DELIVERY, 'indelivery1');
        $this->makeCourierAssignment($inDelivery->id);

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [8]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($bare->id));
        $this->assertFalse($ids->contains($inDelivery->id));
    }

    public function test_assembled_filter_excludes_assigned_when_module_enabled(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned2');
        $this->makeCourierAssignment($assigned->id);
        $bare = $this->makeOrder(Status::ORDER_ASSEMBLED, 'bare2');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [3]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($bare->id));
        $this->assertFalse($ids->contains($assigned->id));
    }

    public function test_assembled_filter_returns_all_when_module_disabled(): void
    {
        $this->setCourierAssignmentModule(false);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned3');
        $this->makeCourierAssignment($assigned->id);
        $bare = $this->makeOrder(Status::ORDER_ASSEMBLED, 'bare3');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [3]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($bare->id));
        $this->assertTrue($ids->contains($assigned->id));
    }

    public function test_assembled_and_assigned_together_return_all_assembled(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned4');
        $this->makeCourierAssignment($assigned->id);
        $bare = $this->makeOrder(Status::ORDER_ASSEMBLED, 'bare4');
        $created = $this->makeOrder(Status::ORDER_CREATED, 'created4');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [3, 8]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertTrue($ids->contains($bare->id));
        $this->assertFalse($ids->contains($created->id));
    }

    public function test_assigned_composes_with_regular_and_issued_codes(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned5');
        $this->makeCourierAssignment($assigned->id);
        $created = $this->makeOrder(Status::ORDER_CREATED, 'created5');
        $issuedPickup = $this->makeOrder(Status::ORDER_FINISHED, 'issued5', ['type_id' => Type::RECEIVE_PICKUP]);
        $deliveredDelivery = $this->makeOrder(Status::ORDER_FINISHED, 'delivered5');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [1, 7, 8]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($created->id));
        $this->assertTrue($ids->contains($issuedPickup->id));
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($deliveredDelivery->id));
    }

    public function test_child_order_assignment_counts_for_parent(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $parent = $this->makeOrder(Status::ORDER_ASSEMBLED, 'parent6');
        $child = $this->makeOrder(Status::ORDER_ASSEMBLED, 'child6', [
            'parent_order_id' => $parent->id,
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
        ]);
        $this->makeCourierAssignment($child->id);

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [8]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($parent->id));
    }

    /**
     * Мягкое удаление назначения (отказ курьера / смена курьера без нового
     * назначения) не должно считаться «назначен курьер» — иначе фильтр
     * статусов расходится с колонкой «Курьер» в таблице (её резолвер тоже
     * игнорирует мягко удалённые delivery_orders).
     */
    public function test_soft_deleted_assignment_is_not_treated_as_assigned(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $order = $this->makeOrder(Status::ORDER_ASSEMBLED, 'softdel7');
        $this->makeCourierAssignment($order->id, true);

        $assignedResp = $this->gql($token, '{ admin_orders_paginate(statuses: [8]) { data { id } paginatorInfo { total } } }');
        $assembledResp = $this->gql($token, '{ admin_orders_paginate(statuses: [3]) { data { id } paginatorInfo { total } } }');

        $assignedResp->assertJsonMissingPath('errors');
        $assembledResp->assertJsonMissingPath('errors');
        $assignedIds = collect($assignedResp->json('data.admin_orders_paginate.data'))->pluck('id');
        $assembledIds = collect($assembledResp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertFalse($assignedIds->contains($order->id));
        $this->assertTrue($assembledIds->contains($order->id));
    }
}
