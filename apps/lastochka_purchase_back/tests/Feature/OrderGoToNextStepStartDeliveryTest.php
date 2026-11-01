<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MID-242: кнопка «В доставке» в АП должна ПРОВОДИТЬ доставку, а не только
 * назначать курьера. OrderGoToNextStep для заказа в статусе ASSEMBLED обязан
 * слать в backend-delivery/order/delivery флаг start_delivery=true — маркер
 * явного намерения админа. Delivery_back по нему стартует рейс даже при
 * включённом модуле courier_assignment. OrderCourierAdd (доп. курьер) флаг
 * НЕ шлёт — его назначения рейс не стартуют.
 */
class OrderGoToNextStepStartDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => 'StartDelivery shop',
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

    /** OrderCanBeChanged: courier_id должен иметь роль COURIER и привязку к магазину заказа */
    private function makeCourier(int $shopId): int
    {
        $role = Role::findOrCreate(Role::COURIER, 'api');
        $userId = \DB::table('users')->insertGetId([
            'name' => 'Курьер',
            'surname' => 'Тестовый',
            'email' => uniqid('courier_') . '@test.local',
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

    private function makeStaffToken(): array
    {
        $role = Role::findOrCreate(Role::ADMIN, 'api');
        $userId = \DB::table('users')->insertGetId([
            'name' => 'Админ',
            'surname' => 'Тестовый',
            'email' => uniqid('sd_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return [$user->id, $user->createToken('test')->plainTextToken];
    }

    public function test_assembled_order_sends_start_delivery_flag(): void
    {
        Http::fake(['*/backend-delivery/*' => Http::response(['message' => 'OK'], 200)]);

        [$adminId, $token] = $this->makeStaffToken();
        $shopId = $this->makeShop();
        $courierId = $this->makeCourier($shopId);
        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'paid' => 1,
            'status_id' => Status::ORDER_ASSEMBLED,
        ]);

        $this->postJson('/graphql-purchase', [
            'query' => 'mutation { OrderGoToNextStep(id: ' . $order->id . ', courier_id: ' . $courierId . ') { code } }',
        ], ['Authorization' => 'Bearer ' . $token]);

        Http::assertSent(function ($request) use ($adminId, $courierId) {
            $body = $request->data();

            return str_contains($request->url(), 'backend-delivery/order/delivery')
                && ($body['start_delivery'] ?? null) === true
                && ($body['courier_id'] ?? null) === $courierId
                && ($body['admin_id'] ?? null) === $adminId;
        });
    }
}
