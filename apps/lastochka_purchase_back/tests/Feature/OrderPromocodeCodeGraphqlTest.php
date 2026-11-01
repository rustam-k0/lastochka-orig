<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderPromocodeCodeGraphqlTest extends TestCase
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
            'email' => uniqid('opc_') . '@test.local',
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

    public function test_admin_orders_item_returns_promocode_code(): void
    {
        $shopId   = $this->makeShop('OPC test shop');
        $clientId = $this->makeClient();
        $token    = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);
        $order->promocode_code = 'SUMMER10';
        $order->save();

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id promocode_code } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertSame('SUMMER10', $resp->json('data.admin_orders_item.promocode_code'));
    }

    public function test_admin_orders_item_returns_null_promocode_code_when_absent(): void
    {
        $shopId   = $this->makeShop('OPC test shop no promo');
        $clientId = $this->makeClient();
        $token    = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id promocode_code } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertNull($resp->json('data.admin_orders_item.promocode_code'));
    }
}
