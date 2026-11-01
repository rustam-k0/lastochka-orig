<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderAssemblyCostGraphqlTest extends TestCase
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
            'email' => uniqid('oac_') . '@test.local',
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

    public function test_admin_orders_item_returns_assembly_cost_and_delivery_price(): void
    {
        $shopId   = $this->makeShop('OAC test shop');
        $clientId = $this->makeClient();
        $token    = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'delivery_price' => 150,
        ]);
        $order->assembly_cost = 99;
        $order->save();

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id assembly_cost delivery_price } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertSame(99, $resp->json('data.admin_orders_item.assembly_cost'));
        $this->assertSame(150.0, (float) $resp->json('data.admin_orders_item.delivery_price'));
    }

    public function test_admin_orders_item_returns_zero_assembly_cost_by_default(): void
    {
        $shopId   = $this->makeShop('OAC test shop no assembly');
        $clientId = $this->makeClient();
        $token    = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { id assembly_cost } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertSame(0, $resp->json('data.admin_orders_item.assembly_cost'));
    }
}
