<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductReplaceReason;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderItemReplaceReasonGraphqlTest extends TestCase
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

    private function makeProduct(string $title): int
    {
        return (int) DB::table('products')->insertGetId([
            'title' => $title,
            'articul' => 'OIRR-' . uniqid(),
            'unit_type_id' => 1,
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

        $userId = \DB::table('users')->insertGetId([
            'name' => 'Test',
            'email' => uniqid('oirr_') . '@test.local',
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

    public function test_admin_order_items_paginate_returns_replace_reason_for_replaced_item(): void
    {
        $shopId = $this->makeShop('OIRR test shop');
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);

        $originProductId = $this->makeProduct('Молоко 1л');
        $replacementProductId = $this->makeProduct('Молоко 1,5л');
        $reason = ProductReplaceReason::create([
            'title' => 'Нет в наличии',
            'is_active' => true,
            'position' => 1,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $replacementProductId,
            'quantity' => 1,
            'price' => 100,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'origin_product_id' => $originProductId,
            'origin_title' => 'Молоко 1л',
            'origin_price' => 90,
            'origin_quantity' => 1,
            'replace_reason_id' => $reason->id,
        ]);

        $resp = $this->gql($token, '{ admin_order_items_paginate(order_id: ' . $order->id . ') { data { id replace_reason { id title } } } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertSame($reason->id, $resp->json('data.admin_order_items_paginate.data.0.replace_reason.id'));
        $this->assertSame('Нет в наличии', $resp->json('data.admin_order_items_paginate.data.0.replace_reason.title'));
    }

    public function test_admin_order_items_paginate_returns_ordered_quantity(): void
    {
        // Весовая позиция после AssemblyFinish: заказано 3 порции, по весу стало 2.
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop('OIRR ordered shop'),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->makeProduct('Филе грудки'),
            'quantity' => 2,
            'ordered_quantity' => 3,
            'actual_weight' => 528,
            'price' => 313.63,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
        ]);

        $resp = $this->gql($this->makeStaffToken(), '{ admin_order_items_paginate(order_id: ' . $order->id . ') { data { quantity ordered_quantity } } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertSame(2, $resp->json('data.admin_order_items_paginate.data.0.quantity'));
        $this->assertSame(3, $resp->json('data.admin_order_items_paginate.data.0.ordered_quantity'));
    }

    public function test_admin_order_items_paginate_returns_null_replace_reason_when_absent(): void
    {
        $shopId = $this->makeShop('OIRR test shop no reason');
        $clientId = $this->makeClient();
        $token = $this->makeStaffToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);

        $productId = $this->makeProduct('Хлеб');

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $productId,
            'quantity' => 1,
            'price' => 50,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
        ]);

        $resp = $this->gql($token, '{ admin_order_items_paginate(order_id: ' . $order->id . ') { data { id replace_reason { id title } } } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertNull($resp->json('data.admin_order_items_paginate.data.0.replace_reason'));
    }
}
