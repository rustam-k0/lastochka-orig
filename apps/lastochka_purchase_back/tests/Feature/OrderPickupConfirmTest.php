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
 * Информация по самовывозу в карточке заказа (Platrum MEN-IN-DEV-11279):
 * OrderPickupConfirm должен фиксировать picked_up_at и picked_up_by_user_id,
 * а не только менять статус.
 */
class OrderPickupConfirmTest extends TestCase
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
            'is_pickup_enabled' => 1,
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

    private function makeStaff(): array
    {
        // Role::ADMIN обходит проверку матрицы доступов (orders.change_status)
        // независимо от текущего значения role_access_matrix_enabled — тест
        // проверяет фиксацию picked_up_at/picked_up_by, а не саму матрицу прав.
        $role = Role::findOrCreate(Role::ADMIN, 'api');

        $userId = \DB::table('users')->insertGetId([
            'name' => 'Иван',
            'surname' => 'Сотрудников',
            'email' => uniqid('opc_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'shop_id' => $this->makeShop('OPC test shop ' . uniqid()),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'type_id' => Type::RECEIVE_PICKUP,
            'status_id' => Status::ORDER_ASSEMBLED,
        ], $attrs));
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    public function test_it_sets_picked_up_at_and_picked_up_by_on_confirm(): void
    {
        [$staff, $token] = $this->makeStaff();
        $order = $this->makeOrder();

        $resp = $this->gql($token, 'mutation { OrderPickupConfirm(id: ' . $order->id . ') { code message } }');

        $resp->assertJsonPath('data.OrderPickupConfirm.code', 200);

        $order->refresh();
        $this->assertSame(Status::ORDER_FINISHED, $order->status_id);
        $this->assertNotNull($order->picked_up_at);
        $this->assertSame($staff->id, $order->picked_up_by_user_id);
    }

    public function test_it_rejects_non_pickup_order(): void
    {
        [, $token] = $this->makeStaff();
        $order = $this->makeOrder(['type_id' => Type::RECEIVE_DELIVERY]);

        $resp = $this->gql($token, 'mutation { OrderPickupConfirm(id: ' . $order->id . ') { code message } }');

        $resp->assertJsonPath('data.OrderPickupConfirm.code', 422);
        $order->refresh();
        $this->assertNull($order->picked_up_at);
        $this->assertNull($order->picked_up_by_user_id);
    }

    public function test_it_rejects_order_not_in_assembled_status(): void
    {
        [, $token] = $this->makeStaff();
        $order = $this->makeOrder(['status_id' => Status::ORDER_PREPARING]);

        $resp = $this->gql($token, 'mutation { OrderPickupConfirm(id: ' . $order->id . ') { code message } }');

        $resp->assertJsonPath('data.OrderPickupConfirm.code', 422);
        $order->refresh();
        $this->assertNull($order->picked_up_at);
    }

    public function test_graphql_exposes_pickup_fields_on_order(): void
    {
        [$staff, $token] = $this->makeStaff();
        $order = $this->makeOrder();
        $order->update(['pickup_ready_at' => now()->subHour()]);

        $this->gql($token, 'mutation { OrderPickupConfirm(id: ' . $order->id . ') { code } }');

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { pickup_ready_at picked_up_at picked_up_by { id name surname } } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertNotNull($resp->json('data.admin_orders_item.pickup_ready_at'));
        $this->assertNotNull($resp->json('data.admin_orders_item.picked_up_at'));
        $this->assertSame($staff->id, $resp->json('data.admin_orders_item.picked_up_by.id'));
        $this->assertSame('Иван', $resp->json('data.admin_orders_item.picked_up_by.name'));
    }

    public function test_it_finishes_child_orders_of_split_pickup(): void
    {
        [, $token] = $this->makeStaff();
        $parent = $this->makeOrder();
        $childOne = $this->makeOrder([
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
            'parent_order_id' => $parent->id,
            'sub_number' => 1,
        ]);
        $childTwo = $this->makeOrder([
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
            'parent_order_id' => $parent->id,
            'sub_number' => 2,
        ]);

        $resp = $this->gql($token, 'mutation { OrderPickupConfirm(id: ' . $parent->id . ') { code message } }');

        $resp->assertJsonPath('data.OrderPickupConfirm.code', 200);

        foreach ([$childOne, $childTwo] as $child) {
            $child->refresh();
            $this->assertSame(Status::ORDER_FINISHED, $child->status_id);
            $this->assertNotNull($child->finished_at);
        }
    }

    public function test_confirming_sub_order_finishes_whole_order(): void
    {
        [$staff, $token] = $this->makeStaff();
        $parent = $this->makeOrder();
        $child = $this->makeOrder([
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
            'parent_order_id' => $parent->id,
            'sub_number' => 1,
        ]);

        $resp = $this->gql($token, 'mutation { OrderPickupConfirm(id: ' . $child->id . ') { code message } }');

        $resp->assertJsonPath('data.OrderPickupConfirm.code', 200);

        $parent->refresh();
        $child->refresh();
        $this->assertSame(Status::ORDER_FINISHED, $parent->status_id);
        $this->assertNotNull($parent->picked_up_at);
        $this->assertSame($staff->id, $parent->picked_up_by_user_id);
        $this->assertSame(Status::ORDER_FINISHED, $child->status_id);
        // Фиксация выдачи — только на корневом заказе
        $this->assertNull($child->picked_up_at);
    }
}
