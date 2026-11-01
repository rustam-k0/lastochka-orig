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
 * Фильтр «Выдан» (виртуальный статус 7) в списке заказов АП.
 * Спека: docs/superpowers/specs/2026-07-18-pickup-issued-filter-design.md
 */
class OrderIssuedStatusFilterTest extends TestCase
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
            'email' => uniqid('oisf_') . '@test.local',
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

    private function makeOrder(int $statusId, int $typeId, string $marker): Order
    {
        $shopId = $this->makeShop('OISF shop ' . $marker);
        $clientId = $this->makeClient();

        return Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'status_id' => $statusId,
            'type_id' => $typeId,
            'payment_type_id' => 1,
            'uuid' => 'oisf-' . $marker . '-' . uniqid(),
        ]);
    }

    public function test_issued_filter_returns_only_finished_pickup(): void
    {
        $token = $this->makeStaffToken();
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued1');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered1');
        $assembledPickup = $this->makeOrder(Status::ORDER_ASSEMBLED, Type::RECEIVE_PICKUP, 'assembled1');
        $cancelledPickup = $this->makeOrder(Status::ORDER_CANCELLED, Type::RECEIVE_PICKUP, 'cancelled1');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [7]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($issued->id));
        $this->assertFalse($ids->contains($delivered->id));
        $this->assertFalse($ids->contains($assembledPickup->id));
        $this->assertFalse($ids->contains($cancelledPickup->id));
    }

    public function test_delivered_filter_excludes_finished_pickup(): void
    {
        $token = $this->makeStaffToken();
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued2');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered2');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [5]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($delivered->id));
        $this->assertFalse($ids->contains($issued->id));
    }

    public function test_delivered_and_issued_together_return_all_finished(): void
    {
        $token = $this->makeStaffToken();
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued3');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered3');
        $created = $this->makeOrder(Status::ORDER_CREATED, Type::RECEIVE_DELIVERY, 'created3');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [5, 7]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($issued->id));
        $this->assertTrue($ids->contains($delivered->id));
        $this->assertFalse($ids->contains($created->id));
    }

    public function test_issued_combines_with_regular_statuses(): void
    {
        $token = $this->makeStaffToken();
        $created = $this->makeOrder(Status::ORDER_CREATED, Type::RECEIVE_DELIVERY, 'created4');
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued4');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered4');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [1, 7]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($created->id));
        $this->assertTrue($ids->contains($issued->id));
        $this->assertFalse($ids->contains($delivered->id));
    }

    public function test_without_statuses_filter_unaffected(): void
    {
        $token = $this->makeStaffToken();
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued5');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered5');

        $resp = $this->gql($token, '{ admin_orders_paginate { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($issued->id));
        $this->assertTrue($ids->contains($delivered->id));
    }
}
