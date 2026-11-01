<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderPickupOnlyRequiredGraphqlTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): int
    {
        return DB::table('shops')->insertGetId([
            'title' => 'PO test shop ' . uniqid(),
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
        return DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeProduct(string $title, bool $pickupOnly): int
    {
        return DB::table('products')->insertGetId([
            'title' => $title,
            'unit_type_id' => 1,
            'status_id' => 1,
            'pickup_only' => $pickupOnly,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrderItem(int $orderId, int $productId): void
    {
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => 1,
            'price' => 100,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeStaffToken(): string
    {
        // Роль admin, а не кастомная: global scope onlyAdmin пропускает кастомные
        // роли только при включённом role_access_matrix_enabled — тест не должен
        // зависеть от состояния этого флага в общей тестовой БД.
        $role = Role::findOrCreate(Role::ADMIN, 'api');

        $userId = DB::table('users')->insertGetId([
            'name' => 'Test',
            'email' => uniqid('po_') . '@test.local',
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

    public function test_pickup_only_required_true_when_order_item_product_is_pickup_only(): void
    {
        $shopId = $this->makeShop();
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $order = Order::factory()->create(['shop_id' => $shopId, 'client_id' => $clientId, 'type_id' => 2, 'payment_type_id' => 1]);
        $wine = $this->makeProduct('Вино', true);
        $this->makeOrderItem($order->id, $wine);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id pickup_only_required } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertTrue($resp->json('data.admin_orders_item.pickup_only_required'));
    }

    public function test_pickup_only_required_false_when_no_pickup_only_products(): void
    {
        $shopId = $this->makeShop();
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $order = Order::factory()->create(['shop_id' => $shopId, 'client_id' => $clientId, 'type_id' => 2, 'payment_type_id' => 1]);
        $milk = $this->makeProduct('Молоко', false);
        $this->makeOrderItem($order->id, $milk);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id pickup_only_required } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertFalse($resp->json('data.admin_orders_item.pickup_only_required'));
    }

    public function test_pickup_only_required_false_when_order_has_no_items(): void
    {
        $shopId = $this->makeShop();
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $order = Order::factory()->create(['shop_id' => $shopId, 'client_id' => $clientId, 'type_id' => 2, 'payment_type_id' => 1]);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id pickup_only_required } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertFalse($resp->json('data.admin_orders_item.pickup_only_required'));
    }

    public function test_parent_order_pickup_only_required_true_when_restricted_product_only_in_child_order(): void
    {
        $shopId = $this->makeShop();
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $parent = Order::factory()->create(['shop_id' => $shopId, 'client_id' => $clientId, 'type_id' => 2, 'payment_type_id' => 1]);
        $milk = $this->makeProduct('Молоко', false);
        $this->makeOrderItem($parent->id, $milk);

        $child = Order::factory()->create(['shop_id' => $shopId, 'client_id' => $clientId, 'type_id' => 2, 'payment_type_id' => 1]);
        $child->parent_order_id = $parent->id;
        $child->save();

        $wine = $this->makeProduct('Вино', true);
        $this->makeOrderItem($child->id, $wine);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $parent->id . ') { id pickup_only_required } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertTrue($resp->json('data.admin_orders_item.pickup_only_required'), 'родительский заказ должен видеть ограничение в дочернем подзаказе по зоне сборки');
    }
}
