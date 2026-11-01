<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderCancelReason;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderCancelReasonTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => 'OrderCancelReason shop',
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

    private function makeClientId(): int
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
            'name' => 'Админ',
            'surname' => 'Тестовый',
            'email' => uniqid('cancel_reason_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return $user->createToken('test')->plainTextToken;
    }

    private function makeOrder(int $shopId, int $clientId): Order
    {
        Http::fake(['*/backend-delivery/*' => Http::response(['message' => 'OK'], 200)]);

        return Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_CREATED,
        ]);
    }

    private function cancelOrder(int $orderId, array $variables, string $token)
    {
        return $this->postJson('/graphql-purchase', [
            'query' => '
                mutation ($order_id: Int, $reason_id: Int, $custom_reason: String) {
                    OrderCancel(order_id: $order_id, reason_id: $reason_id, custom_reason: $custom_reason) {
                        code
                        message
                    }
                }
            ',
            'variables' => array_merge(['order_id' => $orderId], $variables),
        ], ['Authorization' => 'Bearer ' . $token]);
    }

    public function test_staff_cancel_with_reason_id_stores_reason_title_in_history(): void
    {
        $token = $this->makeStaffToken();
        $order = $this->makeOrder($this->makeShop(), $this->makeClientId());
        $reason = OrderCancelReason::where('is_active', true)->firstOrFail();

        $response = $this->cancelOrder($order->id, ['reason_id' => $reason->id], $token);

        $response->assertJson(['data' => ['OrderCancel' => ['code' => 200]]]);
        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'action' => 'canceled',
            'comment' => $reason->title,
        ]);
    }

    public function test_staff_cancel_with_custom_reason_stores_free_text(): void
    {
        $token = $this->makeStaffToken();
        $order = $this->makeOrder($this->makeShop(), $this->makeClientId());

        $response = $this->cancelOrder($order->id, ['custom_reason' => 'Клиент грубил оператору'], $token);

        $response->assertJson(['data' => ['OrderCancel' => ['code' => 200]]]);
        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'action' => 'canceled',
            'comment' => 'Клиент грубил оператору',
        ]);
    }

    public function test_custom_reason_takes_priority_over_reason_id_when_both_present(): void
    {
        $token = $this->makeStaffToken();
        $order = $this->makeOrder($this->makeShop(), $this->makeClientId());
        $reason = OrderCancelReason::where('is_active', true)->firstOrFail();

        $response = $this->cancelOrder($order->id, [
            'reason_id' => $reason->id,
            'custom_reason' => 'Свой текст важнее',
        ], $token);

        $response->assertJson(['data' => ['OrderCancel' => ['code' => 200]]]);
        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'action' => 'canceled',
            'comment' => 'Свой текст важнее',
        ]);
    }

    public function test_staff_cancel_without_any_reason_is_rejected(): void
    {
        $token = $this->makeStaffToken();
        $order = $this->makeOrder($this->makeShop(), $this->makeClientId());

        $response = $this->cancelOrder($order->id, [], $token);

        $json = $response->json();
        $this->assertArrayHasKey('errors', $json);
        $this->assertNull($json['data']['OrderCancel'] ?? null);
        $this->assertSame(Status::ORDER_CREATED, $order->fresh()->status_id);
    }

    public function test_staff_cancel_with_inactive_reason_id_is_rejected(): void
    {
        $token = $this->makeStaffToken();
        $order = $this->makeOrder($this->makeShop(), $this->makeClientId());
        $reason = OrderCancelReason::first();
        $reason->update(['is_active' => false]);

        $response = $this->cancelOrder($order->id, ['reason_id' => $reason->id], $token);

        $json = $response->json();
        $this->assertArrayHasKey('errors', $json);
        $this->assertNull($json['data']['OrderCancel'] ?? null);
        $this->assertSame(Status::ORDER_CREATED, $order->fresh()->status_id);
    }

    public function test_client_self_cancel_without_reason_still_works(): void
    {
        $clientId = $this->makeClientId();
        $client = Client::findOrFail($clientId);
        $token = $client->createToken('test')->plainTextToken;

        $order = $this->makeOrder($this->makeShop(), $clientId);

        $response = $this->cancelOrder($order->id, [], $token);

        $response->assertJson(['data' => ['OrderCancel' => ['code' => 200]]]);
        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'action' => 'canceled',
            'comment' => null,
        ]);
    }

    public function test_staff_cancel_cascades_history_to_active_child_orders(): void
    {
        $token = $this->makeStaffToken();
        $shopId = $this->makeShop();
        $clientId = $this->makeClientId();
        $order = $this->makeOrder($shopId, $clientId);
        $child = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_PREPARING,
            'parent_order_id' => $order->id,
        ]);
        $reason = OrderCancelReason::where('is_active', true)->firstOrFail();

        $response = $this->cancelOrder($order->id, ['reason_id' => $reason->id], $token);

        $response->assertJson(['data' => ['OrderCancel' => ['code' => 200]]]);
        $this->assertDatabaseHas('order_history', [
            'order_id' => $child->id,
            'action' => 'canceled',
            'comment' => $reason->title,
        ]);
        $this->assertSame(Status::ORDER_CANCELED, (int) $child->fresh()->status_id);
    }
}
