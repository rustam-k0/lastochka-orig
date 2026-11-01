<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Фильтр по статусу оплаты в списке заказов (Platrum MEN-IN-DEV-11274).
 */
class OrderPaymentStatusFilterTest extends TestCase
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
            'email' => uniqid('opsf_') . '@test.local',
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

    private function makeOrder(?string $paymentStatus, string $marker): Order
    {
        $shopId = $this->makeShop('OPSF shop ' . $marker);
        $clientId = $this->makeClient();

        return Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'payment_status' => $paymentStatus,
            'uuid' => 'opsf-' . $marker . '-' . uniqid(),
        ]);
    }

    public function test_filters_by_paid_status(): void
    {
        $token = $this->makeStaffToken();
        $paid = $this->makeOrder('paid', 'paid1');
        $this->makeOrder('pending', 'pending1');
        $this->makeOrder(null, 'null1');

        $resp = $this->gql($token, '{ admin_orders_paginate(payment_statuses: ["paid"]) { data { id payment_status } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($paid->id));
        $this->assertCount(1, $ids);
    }

    public function test_pending_bucket_includes_hold_and_null(): void
    {
        $token = $this->makeStaffToken();
        $pending = $this->makeOrder('pending', 'pending2');
        $hold = $this->makeOrder('hold', 'hold2');
        $noStatus = $this->makeOrder(null, 'null2');
        $paid = $this->makeOrder('paid', 'paid2');

        $resp = $this->gql($token, '{ admin_orders_paginate(payment_statuses: ["pending"]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertTrue($ids->contains($hold->id));
        $this->assertTrue($ids->contains($noStatus->id));
        $this->assertFalse($ids->contains($paid->id));
    }

    public function test_filters_by_failed_status(): void
    {
        $token = $this->makeStaffToken();
        $failed = $this->makeOrder('failed', 'failed1');
        $this->makeOrder('paid', 'paid3');

        $resp = $this->gql($token, '{ admin_orders_paginate(payment_statuses: ["failed"]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertEquals([$failed->id], $ids->all());
    }

    public function test_filters_by_refunded_status(): void
    {
        $token = $this->makeStaffToken();
        $refunded = $this->makeOrder('refunded', 'refunded1');
        $this->makeOrder('paid', 'paid4');

        $resp = $this->gql($token, '{ admin_orders_paginate(payment_statuses: ["refunded"]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertEquals([$refunded->id], $ids->all());
    }

    public function test_multiple_statuses_combine_with_or(): void
    {
        $token = $this->makeStaffToken();
        $paid = $this->makeOrder('paid', 'paid5');
        $refunded = $this->makeOrder('refunded', 'refunded5');
        $this->makeOrder('pending', 'pending5');

        $resp = $this->gql($token, '{ admin_orders_paginate(payment_statuses: ["paid", "refunded"]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id')->sort()->values();
        $this->assertEquals([$paid->id, $refunded->id], $ids->sort()->values()->all());
    }

    public function test_graphql_exposes_payment_status_field(): void
    {
        $token = $this->makeStaffToken();
        $order = $this->makeOrder('failed', 'expose1');

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id payment_status } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertSame('failed', $resp->json('data.admin_orders_item.payment_status'));
    }
}
