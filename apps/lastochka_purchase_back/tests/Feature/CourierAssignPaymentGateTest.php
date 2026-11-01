<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Гейты назначения курьера из админки при модуле «Списание оплаты сборщиком»
 * (collector_payment): неоплаченный card-заказ нельзя передать в доставку,
 * пока оплата не списана. Плюс проверка generic-фильтра payment_statuses
 * на новом значении awaiting_surcharge.
 */
class CourierAssignPaymentGateTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(string $marker): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => 'CAPG shop ' . $marker,
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
            'email' => uniqid('capg_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return $user->createToken('test')->plainTextToken;
    }

    /**
     * OrderCanBeChanged требует, чтобы courier_id принадлежал роли COURIER
     * и был привязан к магазину заказа (user_shops).
     */
    private function makeCourier(int $shopId): int
    {
        $role = Role::findOrCreate(Role::COURIER, 'api');
        $userId = \DB::table('users')->insertGetId([
            'name' => 'Курьер',
            'surname' => 'Тестовый',
            'email' => uniqid('capg_courier_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        \DB::table('user_shops')->insert([
            'shop_id' => $shopId,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    private function setModules(array $modules): void
    {
        $value = [];
        foreach ($modules as $key => $enabled) {
            $value[$key] = ['label' => $key, 'enabled' => $enabled];
        }

        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode($value), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    public function test_go_to_next_step_rejected_for_unpaid_card_order_when_module_enabled(): void
    {
        $this->setModules(['collector_payment' => true]);
        Http::fake();
        $token = $this->makeStaffToken();
        $shopId = $this->makeShop('unpaid1');
        $courierId = $this->makeCourier($shopId);
        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $this->makeClient(),
            'payment_type_id' => Type::PAYMENT_CARD,
            'paid' => 0,
            'type_id' => Type::RECEIVE_DELIVERY,
            'status_id' => Status::ORDER_ASSEMBLED,
        ]);

        $resp = $this->gql($token, '
            mutation { OrderGoToNextStep(id: ' . $order->id . ', courier_id: ' . $courierId . ') { code message } }
        ');

        $this->assertStringContainsString(
            'Заказ не оплачен: спишите оплату перед назначением курьера',
            json_encode($resp->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        Http::assertNothingSent();
    }

    public function test_go_to_next_step_passes_for_paid_card_order_when_module_enabled(): void
    {
        $this->setModules(['collector_payment' => true]);
        Http::fake(['*/backend-delivery/order/delivery' => Http::response(['message' => 'OK'], 200)]);
        $token = $this->makeStaffToken();
        $shopId = $this->makeShop('paid1');
        $courierId = $this->makeCourier($shopId);
        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $this->makeClient(),
            'payment_type_id' => Type::PAYMENT_CARD,
            'paid' => 1,
            'type_id' => Type::RECEIVE_DELIVERY,
            'status_id' => Status::ORDER_ASSEMBLED,
        ]);

        $resp = $this->gql($token, '
            mutation { OrderGoToNextStep(id: ' . $order->id . ', courier_id: ' . $courierId . ') { code message } }
        ');

        $resp->assertJsonMissingPath('errors');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'backend-delivery/order/delivery')
            && $request['courier_id'] == $courierId
            && $request['order_id'] == $order->id);
    }

    public function test_go_to_next_step_passes_for_unpaid_card_order_when_module_disabled(): void
    {
        $this->setModules(['collector_payment' => false]);
        Http::fake(['*/backend-delivery/order/delivery' => Http::response(['message' => 'OK'], 200)]);
        $token = $this->makeStaffToken();
        $shopId = $this->makeShop('moduleoff1');
        $courierId = $this->makeCourier($shopId);
        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $this->makeClient(),
            'payment_type_id' => Type::PAYMENT_CARD,
            'paid' => 0,
            'type_id' => Type::RECEIVE_DELIVERY,
            'status_id' => Status::ORDER_ASSEMBLED,
        ]);

        $resp = $this->gql($token, '
            mutation { OrderGoToNextStep(id: ' . $order->id . ', courier_id: ' . $courierId . ') { code message } }
        ');

        $resp->assertJsonMissingPath('errors');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'backend-delivery/order/delivery'));
    }

    public function test_go_to_next_step_from_created_status_unaffected_by_payment_gate(): void
    {
        // Гейт зашит в checkOrderAssembled — переход из CREATED (сборщик)
        // идёт другой веткой match и не должен блокироваться неоплаченным card.
        $this->setModules(['collector_payment' => true]);
        Http::fake(['*/backend-delivery/order/prepare' => Http::response(['message' => 'OK'], 200)]);
        $token = $this->makeStaffToken();
        $shopId = $this->makeShop('created1');

        $role = Role::findOrCreate(Role::COLLECTOR, 'api');
        $collectorId = \DB::table('users')->insertGetId([
            'name' => 'Сборщик',
            'email' => uniqid('capg_collector_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $collectorUser = User::withoutGlobalScope('onlyAdmin')->findOrFail($collectorId);
        $collectorUser->assignRole($role);
        \DB::table('user_shops')->insert([
            'shop_id' => $shopId,
            'user_id' => $collectorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $this->makeClient(),
            'payment_type_id' => Type::PAYMENT_CARD,
            'paid' => 0,
            'type_id' => Type::RECEIVE_DELIVERY,
            'status_id' => Status::ORDER_CREATED,
        ]);

        $resp = $this->gql($token, '
            mutation { OrderGoToNextStep(id: ' . $order->id . ', collector_id: ' . $collectorId . ') { code message } }
        ');

        $resp->assertJsonMissingPath('errors');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'backend-delivery/order/prepare'));
    }

    public function test_courier_add_rejected_for_unpaid_heavy_card_order_when_module_enabled(): void
    {
        $this->setModules(['heavy_orders' => true, 'collector_payment' => true]);
        Http::fake();
        $token = $this->makeStaffToken();
        $shopId = $this->makeShop('heavyunpaid1');
        $courierId = $this->makeCourier($shopId);
        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $this->makeClient(),
            'payment_type_id' => Type::PAYMENT_CARD,
            'paid' => 0,
            'is_heavy' => 1,
            'type_id' => Type::RECEIVE_DELIVERY,
            'status_id' => Status::ORDER_IN_DELIVERY,
        ]);

        $resp = $this->gql($token, '
            mutation { OrderCourierAdd(id: ' . $order->id . ', courier_id: ' . $courierId . ') { code message } }
        ');

        $this->assertStringContainsString(
            'Заказ не оплачен: спишите оплату перед назначением курьера',
            json_encode($resp->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        Http::assertNothingSent();
    }

    public function test_awaiting_surcharge_payment_status_found_by_filter_and_excluded_from_pending(): void
    {
        $token = $this->makeStaffToken();
        $shopId = $this->makeShop('surcharge1');
        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $this->makeClient(),
            'payment_type_id' => Type::PAYMENT_CARD,
            'payment_status' => 'awaiting_surcharge',
            'uuid' => 'capg-surcharge-' . uniqid(),
        ]);

        $foundResp = $this->gql($token, '{ admin_orders_paginate(payment_statuses: ["awaiting_surcharge"]) { data { id payment_status } paginatorInfo { total } } }');
        $foundResp->assertJsonMissingPath('errors');
        $foundIds = collect($foundResp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($foundIds->contains($order->id));

        $pendingResp = $this->gql($token, '{ admin_orders_paginate(payment_statuses: ["pending"]) { data { id } paginatorInfo { total } } }');
        $pendingResp->assertJsonMissingPath('errors');
        $pendingIds = collect($pendingResp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertFalse($pendingIds->contains($order->id));
    }
}
