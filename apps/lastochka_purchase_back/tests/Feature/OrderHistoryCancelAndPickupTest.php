<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderHistoryCancelAndPickupTest extends TestCase
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
        $role = Role::findOrCreate(Role::ADMIN, 'api');
        $userId = \DB::table('users')->insertGetId([
            'name' => 'Иван',
            'surname' => 'Отменяев',
            'email' => uniqid('ohcp_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    public function test_order_cancel_logs_canceled_action(): void
    {
        Http::fake();

        [$staff, $token] = $this->makeStaff();
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop('Cancel history shop'),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_PREPARING,
        ]);

        $this->gql($token, 'mutation { OrderCancel(order_id: ' . $order->id . ', custom_reason: "Проверка истории") { code message } }');

        $row = OrderHistory::where('order_id', $order->id)->where('action', OrderHistory::ACTION_CANCELED)->first();
        $this->assertNotNull($row);
        $this->assertSame($staff->id, $row->actor_id);
        $this->assertStringContainsString('Отменяев', $row->actor_name);
    }

    public function test_pickup_confirm_logs_delivery_completed_action(): void
    {
        [$staff, $token] = $this->makeStaff();
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop('Pickup history shop'),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'type_id' => Type::RECEIVE_PICKUP,
            'status_id' => Status::ORDER_ASSEMBLED,
        ]);

        $this->gql($token, 'mutation { OrderPickupConfirm(id: ' . $order->id . ') { code message } }');

        $row = OrderHistory::where('order_id', $order->id)->where('action', OrderHistory::ACTION_DELIVERY_COMPLETED)->first();
        $this->assertNotNull($row);
        $this->assertSame($staff->id, $row->actor_id);
        $this->assertSame('Выдан', $row->new_value);
    }
}
