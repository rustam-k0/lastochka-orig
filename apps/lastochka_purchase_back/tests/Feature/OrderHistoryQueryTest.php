<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryQueryTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => 'History query shop',
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
            'email' => uniqid('ohq_') . '@test.local',
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

    public function test_admin_order_history_returns_entries_ordered_by_newest_first(): void
    {
        $token = $this->makeStaffToken();
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop(),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_CREATED,
        ]);

        OrderHistory::log($order->id, OrderHistory::ACTION_CREATED, OrderHistory::ACTOR_CLIENT, null, 'Клиент', null, null);
        OrderHistory::log($order->id, OrderHistory::ACTION_STATUS_CHANGED, OrderHistory::ACTOR_STAFF, 1, 'Иванов И.И.', 'Создан', 'В сборке');

        $resp = $this->gql($token, '{ admin_order_history(order_id: ' . $order->id . ') { id action actor_type actor_name old_value new_value comment created_at } }');

        $resp->assertJsonMissingPath('errors');
        $rows = $resp->json('data.admin_order_history');
        $this->assertCount(2, $rows);
        $this->assertSame('status_changed', $rows[0]['action']);
        $this->assertSame('created', $rows[1]['action']);
    }

    public function test_admin_order_history_scoped_to_requested_order_only(): void
    {
        $token = $this->makeStaffToken();
        $orderA = Order::factory()->create([
            'shop_id' => $this->makeShop(),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_CREATED,
        ]);
        $orderB = Order::factory()->create([
            'shop_id' => $this->makeShop(),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_CREATED,
        ]);

        OrderHistory::log($orderA->id, OrderHistory::ACTION_CREATED, OrderHistory::ACTOR_CLIENT, null, 'Клиент', null, null);
        OrderHistory::log($orderB->id, OrderHistory::ACTION_CREATED, OrderHistory::ACTOR_CLIENT, null, 'Клиент', null, null);

        $resp = $this->gql($token, '{ admin_order_history(order_id: ' . $orderA->id . ') { id } }');

        $this->assertCount(1, $resp->json('data.admin_order_history'));
    }

    public function test_admin_order_history_includes_child_order_entries(): void
    {
        $token = $this->makeStaffToken();
        $shopId = $this->makeShop();
        $clientId = $this->makeClient();

        $parent = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_CREATED,
        ]);
        $child = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_CREATED,
            'parent_order_id' => $parent->id,
        ]);

        OrderHistory::log($parent->id, OrderHistory::ACTION_CREATED, OrderHistory::ACTOR_CLIENT, null, 'Клиент', null, null);
        OrderHistory::log($child->id, OrderHistory::ACTION_ITEM_REPLACED, OrderHistory::ACTOR_STAFF, 1, 'Иванов И.И.', 'Было', 'Стало');

        $resp = $this->gql($token, '{ admin_order_history(order_id: ' . $parent->id . ') { id order_id action } }');

        $resp->assertJsonMissingPath('errors');
        $rows = $resp->json('data.admin_order_history');
        $this->assertCount(2, $rows);
        $orderIds = array_column($rows, 'order_id');
        $this->assertContains($parent->id, $orderIds);
        $this->assertContains($child->id, $orderIds);
    }
}
