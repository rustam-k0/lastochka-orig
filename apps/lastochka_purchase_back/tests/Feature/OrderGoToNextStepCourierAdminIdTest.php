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
 * Platrum MEN-IN-DEV-11270: OrderGoToNextStep должен передавать admin_id
 * текущего сотрудника в теле запроса к backend-delivery/order/delivery,
 * иначе delivery_back не сможет залогировать, кто назначил курьера.
 */
class OrderGoToNextStepCourierAdminIdTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => 'GoToNextStep courier shop',
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

    /**
     * OrderCanBeChanged требует, чтобы courier_id принадлежал роли COURIER
     * и был привязан к магазину заказа (user_shops), иначе валидатор
     * OrderGoToNextStepValidator отклонит мутацию раньше, чем она уйдёт в HTTP.
     */
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
            'surname' => 'Курьерский',
            'email' => uniqid('gtnsc_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return [$user->id, $user->createToken('test')->plainTextToken];
    }

    public function test_admin_id_is_sent_in_delivery_request_body(): void
    {
        Http::fake(['*/backend-delivery/*' => Http::response(['message' => 'OK'], 200)]);

        [$adminId, $token] = $this->makeStaffToken();
        $shopId = $this->makeShop();
        $courierId = $this->makeCourier($shopId);
        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'type_id' => Type::RECEIVE_DELIVERY,
            'status_id' => Status::ORDER_ASSEMBLED,
        ]);

        $this->postJson('/graphql-purchase', [
            'query' => 'mutation { OrderGoToNextStep(id: ' . $order->id . ', courier_id: ' . $courierId . ') { code } }',
        ], ['Authorization' => 'Bearer ' . $token]);

        Http::assertSent(function ($request) use ($adminId, $courierId) {
            return str_contains($request->url(), 'backend-delivery/order/delivery')
                && $request['admin_id'] === $adminId
                && $request['courier_id'] === $courierId;
        });
    }
}
