<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderCardMissingFieldsGraphqlTest extends TestCase
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
        // Роль admin, а не кастомная: global scope onlyAdmin пропускает кастомные
        // роли только при включённом role_access_matrix_enabled — тест не должен
        // зависеть от состояния этого флага в общей тестовой БД.
        $role = Role::findOrCreate(Role::ADMIN, 'api');

        $userId = \DB::table('users')->insertGetId([
            'name' => 'Test',
            'email' => uniqid('ocf_') . '@test.local',
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

    public function test_admin_orders_item_returns_new_order_card_fields(): void
    {
        $shopId   = $this->makeShop('OCF test shop');
        $clientId = $this->makeClient();
        $token    = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);
        $order->items_subtotal = 1234.56;
        $order->paid = true;
        $order->payment_status = 'paid';
        $order->bonus_spent = 30;
        $order->bonus_earned = 45;
        $order->save();

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id items_subtotal is_paid bonus_spent bonus_earned } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertEquals(1234.56, $resp->json('data.admin_orders_item.items_subtotal'));
        $this->assertTrue($resp->json('data.admin_orders_item.is_paid'));
        $this->assertSame(30, $resp->json('data.admin_orders_item.bonus_spent'));
        $this->assertSame(45, $resp->json('data.admin_orders_item.bonus_earned'));
    }

    public function test_admin_orders_item_returns_defaults_when_fields_absent(): void
    {
        $shopId   = $this->makeShop('OCF test shop defaults');
        $clientId = $this->makeClient();
        $token    = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id items_subtotal is_paid bonus_spent bonus_earned } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertNull($resp->json('data.admin_orders_item.items_subtotal'));
        $this->assertFalse($resp->json('data.admin_orders_item.is_paid'));
        $this->assertSame(0, $resp->json('data.admin_orders_item.bonus_spent'));
        $this->assertSame(0, $resp->json('data.admin_orders_item.bonus_earned'));
    }

    public function test_is_paid_false_when_order_refunded_despite_paid_flag(): void
    {
        $shopId   = $this->makeShop('OCF refund shop');
        $clientId = $this->makeClient();
        $token    = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);
        $order->paid = true;
        $order->payment_status = 'refunded';
        $order->save();

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id is_paid } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertFalse($resp->json('data.admin_orders_item.is_paid'));
    }

    public function test_is_paid_true_when_order_paid_despite_failed_status(): void
    {
        $shopId   = $this->makeShop('OCF failed shop');
        $clientId = $this->makeClient();
        $token    = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);
        $order->paid = true;
        $order->payment_status = 'failed';
        $order->save();

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id is_paid } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertTrue($resp->json('data.admin_orders_item.is_paid'));
    }

    public function test_is_paid_true_for_legacy_order_without_payment_status(): void
    {
        $shopId   = $this->makeShop('OCF legacy shop');
        $clientId = $this->makeClient();
        $token    = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 2,
        ]);
        $order->paid = true;
        $order->payment_status = null;
        $order->save();

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id is_paid } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertTrue($resp->json('data.admin_orders_item.is_paid'));
    }
}
