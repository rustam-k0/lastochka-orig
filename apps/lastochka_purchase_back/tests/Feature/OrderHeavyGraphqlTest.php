<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Фича «Тяжёлый заказ / совместная доставка» (модуль heavy_orders):
 * - Order.is_heavy в GraphQL-типе (потребляет admin_front);
 * - мутация OrderCourierAdd — добавление ДОП. курьера на тяжёлый заказ
 *   из админки (зеркало HTTP-вызова OrderGoToNextStep на delivery_back).
 */
class OrderHeavyGraphqlTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;
    private int $courierId;

    protected function setUp(): void
    {
        parent::setUp();

        [$staff, $token] = $this->makeStaff();
        $this->token = $token;
        // courier_id валидируется как integer (не exists) — но берём реальный
        // user для реалистичности вызова delivery_back.
        $this->courierId = $this->makeUserId('Курьер');
    }

    private function makeStaff(): array
    {
        // Role::ADMIN обходит матрицу доступов (orders.assign_courier) —
        // тест проверяет саму мутацию, а не матрицу прав (см. OrderPickupConfirmTest).
        $role = Role::findOrCreate(Role::ADMIN, 'api');

        $userId = $this->makeUserId('Админ');

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function makeUserId(string $name): int
    {
        return \DB::table('users')->insertGetId([
            'name' => $name,
            'surname' => 'Тестовый ' . uniqid(),
            'email' => uniqid('ohg_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeShop(): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => 'Heavy test shop ' . uniqid(),
            'id_1c' => random_int(900000, 999999),
            'default' => 1,
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
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrder(array $attrs = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->makeShop(),
            'client_id' => $this->makeClient(),
            'uuid' => 'ohg-' . uniqid(),
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => Type::RECEIVE_DELIVERY,
            'status_id' => Status::ORDER_ASSEMBLED,
            'amount' => 100,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function enableModule(bool $enabled = true): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            [
                'value' => json_encode(['heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => $enabled]]),
                'type' => 'json',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function postGraphql(string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $this->token,
        ]);
    }

    /** @test */
    public function order_exposes_is_heavy()
    {
        $orderId = $this->insertOrder(['is_heavy' => 1, 'couriers_count' => 2]);

        $response = $this->postGraphql('
            { admin_orders_item(id: ' . $orderId . ') { id is_heavy } }
        ');

        $response->assertJsonPath('data.admin_orders_item.is_heavy', true);
    }

    /** @test */
    public function courier_add_calls_delivery_endpoint_for_heavy_order()
    {
        $this->enableModule();
        Http::fake(['*/backend-delivery/order/delivery' => Http::response(['message' => 'OK'], 200)]);
        $orderId = $this->insertOrder(['is_heavy' => 1, 'status_id' => Status::ORDER_IN_DELIVERY, 'type_id' => 2]);

        $response = $this->postGraphql('
            mutation { OrderCourierAdd(id: ' . $orderId . ', courier_id: ' . $this->courierId . ') { code message } }
        ');

        $response->assertJsonPath('data.OrderCourierAdd.code', 200);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'backend-delivery/order/delivery')
            && $request['courier_id'] == $this->courierId
            && $request['order_id'] == $orderId);
    }

    /** @test */
    public function courier_add_rejected_for_regular_order()
    {
        $this->enableModule();
        Http::fake();
        $orderId = $this->insertOrder(['is_heavy' => 0, 'status_id' => Status::ORDER_IN_DELIVERY, 'type_id' => 2]);

        $response = $this->postGraphql('
            mutation { OrderCourierAdd(id: ' . $orderId . ', courier_id: ' . $this->courierId . ') { code message } }
        ');

        $this->assertNotEmpty($response->json('errors'));
        Http::assertNothingSent();
    }

    /** @test */
    public function courier_add_rejected_when_module_disabled()
    {
        $this->enableModule(false);
        Http::fake();
        $orderId = $this->insertOrder(['is_heavy' => 1, 'status_id' => Status::ORDER_IN_DELIVERY, 'type_id' => 2]);

        $response = $this->postGraphql('
            mutation { OrderCourierAdd(id: ' . $orderId . ', courier_id: ' . $this->courierId . ') { code message } }
        ');

        $this->assertNotEmpty($response->json('errors'));
        Http::assertNothingSent();
    }

    /** @test */
    public function courier_add_rejected_with_neutral_message_when_both_modules_disabled()
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            [
                'value' => json_encode([
                    'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => false],
                    'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => false],
                ]),
                'type' => 'json',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        Http::fake();
        $orderId = $this->insertOrder(['is_heavy' => 1, 'status_id' => Status::ORDER_IN_DELIVERY, 'type_id' => 2]);

        $response = $this->postGraphql('
            mutation { OrderCourierAdd(id: ' . $orderId . ', courier_id: ' . $this->courierId . ') { code message } }
        ');

        $this->assertStringContainsString(
            'Назначение курьеров отключено',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        Http::assertNothingSent();
    }

    /** @test */
    public function courier_add_works_under_courier_assignment_module_only()
    {
        // Platrum 12180: совместная доставка тяжёлых входит в courier_assignment —
        // доп. курьер добавляется и при выключенном heavy_orders
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            [
                'value' => json_encode([
                    'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => false],
                    'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => true],
                ]),
                'type' => 'json',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        Http::fake(['*/backend-delivery/order/delivery' => Http::response(['message' => 'OK'], 200)]);
        $orderId = $this->insertOrder(['is_heavy' => 1, 'status_id' => Status::ORDER_IN_DELIVERY, 'type_id' => 2]);

        $response = $this->postGraphql('
            mutation { OrderCourierAdd(id: ' . $orderId . ', courier_id: ' . $this->courierId . ') { code message } }
        ');

        $response->assertJsonPath('data.OrderCourierAdd.code', 200);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'backend-delivery/order/delivery'));
    }
}
