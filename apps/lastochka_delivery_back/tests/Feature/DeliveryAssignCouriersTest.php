<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class DeliveryAssignCouriersTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $collector;
    private int $courier1Id;
    private int $courier2Id;

    protected function setUp(): void
    {
        parent::setUp();

        // FCM не должен ходить наружу
        $this->mock(FcmNotificationService::class)
            ->shouldReceive('sendToUser')->andReturnNull();

        // default=1 — fallback-фильтр ShopService(null) в scopeCurrentShop (валидатор
        // ходит через Order::currentShop()->find, как DeliveryTakeOrderValidator).
        // Тот же паттерн, что в ProductPluFieldTest.
        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Assign shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // orders.client_id — NOT NULL FK на clients (clients в mendev_test пуст).
        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->collector = $this->makeStaff('collector');
        $this->courier1Id = $this->makeStaff('courier')->id;
        $this->courier2Id = $this->makeStaff('courier')->id;
    }

    private function makeStaff(string $roleName): User
    {
        // User в этом репо полностью guarded (нет $fillable) — mass-assignment
        // через User::create бросает MassAssignmentException. Используем проверенный
        // паттерн репозитория: сырой insert + findOrFail (см. OrderAssignPermissionTest).
        $userId = \DB::table('users')->insertGetId([
            'name' => ucfirst($roleName), 'surname' => 'Тестовый ' . uniqid(),
            'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::findOrFail($userId);
        $user->assignRole(Role::findOrCreate($roleName, 'api'));

        \DB::table('shifts')->insert([
            'user_id' => $user->id, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function insertOrder(array $attrs = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'dac-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function enableModule(bool $enabled = true): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private const MUTATION = '
        mutation DeliveryAssignCouriers($order_id: Int!, $courier_ids: [Int!]!) {
            DeliveryAssignCouriers(order_id: $order_id, courier_ids: $courier_ids) { code message }
        }
    ';

    private function assign(int $orderId, array $courierIds)
    {
        // Эндпоинт GraphQL в этом репо — /graphql-delivery (config lighthouse.route.uri).
        // Используем MakesGraphQLRequests (паттерн ProductPluFieldTest/OrderAssignPermissionTest),
        // он сам берёт корректный uri.
        $this->actingAs($this->collector, 'api');

        return $this->graphQL(self::MUTATION, ['order_id' => $orderId, 'courier_ids' => $courierIds]);
    }

    /** @test */
    public function collector_assigns_two_couriers_to_heavy_order()
    {
        $this->enableModule();
        $orderId = $this->insertOrder(['is_heavy' => 1]);

        $response = $this->assign($orderId, [$this->courier1Id, $this->courier2Id]);

        $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
        $this->assertSame(2, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
        $this->assertSame(2, (int) Order::find($orderId)->couriers_count);
        // Назначение сборщиком запускает доставку (зеркало REST-пути админа)
        $this->assertSame(Status::ORDER_IN_DELIVERY, (int) Order::find($orderId)->status_id);
    }

    /** @test */
    public function regular_order_accepts_only_single_courier()
    {
        $this->enableModule();
        $orderId = $this->insertOrder(['is_heavy' => 0]);

        $response = $this->assign($orderId, [$this->courier1Id, $this->courier2Id]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function single_courier_assignment_works_for_regular_order()
    {
        $this->enableModule();
        $orderId = $this->insertOrder(['is_heavy' => 0]);

        $response = $this->assign($orderId, [$this->courier1Id]);

        $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
        $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function mutation_rejected_when_module_disabled()
    {
        $this->enableModule(false);
        $orderId = $this->insertOrder(['is_heavy' => 1]);

        $response = $this->assign($orderId, [$this->courier1Id]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function shift_couriers_returns_couriers_of_collectors_shop()
    {
        $this->enableModule();
        // курьер другого магазина не должен попасть в выдачу
        $otherShopId = \DB::table('shops')->insertGetId([
            'title' => 'Other shop', 'id_1c' => random_int(900000, 999999), 'default' => 0,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $strayCourierId = \DB::table('users')->insertGetId([
            'name' => 'Чужой', 'surname' => 'Курьер', 'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $strayCourier = User::findOrFail($strayCourierId);
        $strayCourier->assignRole(Role::findOrCreate('courier', 'api'));
        \DB::table('shifts')->insert([
            'user_id' => $strayCourier->id, 'shop_id' => $otherShopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->collector, 'api');
        $response = $this->graphQL('{ shift_couriers { id } heavy_orders_enabled }');

        $ids = collect($response->json('data.shift_couriers'))->pluck('id')->all();
        $this->assertContains($this->courier1Id, $ids);
        $this->assertContains($this->courier2Id, $ids);
        $this->assertNotContains($strayCourier->id, $ids);
        $this->assertTrue($response->json('data.heavy_orders_enabled'));
    }

    private function enableCourierAssignmentModule(bool $enabled = true, bool $heavy = false): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode([
                'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled],
                'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => $heavy],
            ]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** @test */
    public function courier_assignment_module_allows_single_assignment_without_heavy_orders()
    {
        $this->enableCourierAssignmentModule(true, false);
        $orderId = $this->insertOrder(['is_heavy' => 0]);

        $response = $this->assign($orderId, [$this->courier1Id]);

        $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
        $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
        // При courier_assignment назначение НЕ стартует рейс: заказ остаётся
        // «Назначен курьер» (см. CourierAssignedStatusTest, спека 2026-07-18)
        $this->assertSame(Status::ORDER_ASSEMBLED, (int) Order::find($orderId)->status_id);
    }

    /** @test */
    public function courier_assignment_module_still_rejects_multiple_couriers_for_regular_order()
    {
        $this->enableCourierAssignmentModule(true, false);
        $orderId = $this->insertOrder(['is_heavy' => 0]);

        $response = $this->assign($orderId, [$this->courier1Id, $this->courier2Id]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function courier_assignment_module_allows_multiple_couriers_for_heavy_order_without_heavy_module()
    {
        // Platrum 12180: совместная доставка тяжёлых входит в courier_assignment.
        // Heavy-ветки валидатора активны при heavy_orders ИЛИ courier_assignment.
        $this->enableCourierAssignmentModule(true, false);
        $orderId = $this->insertOrder(['is_heavy' => 1]);

        $response = $this->assign($orderId, [$this->courier1Id, $this->courier2Id]);

        $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
        $this->assertSame(2, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
        $this->assertSame(2, (int) Order::find($orderId)->couriers_count);
    }

    /** @test */
    public function courier_assignment_module_allows_adding_courier_to_heavy_order_in_delivery()
    {
        // Донабор курьера к тяжёлому заказу в пути (Platrum 12180)
        $this->enableCourierAssignmentModule(true, false);
        $orderId = $this->insertOrder(['is_heavy' => 1]);

        $this->assign($orderId, [$this->courier1Id])
            ->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);

        $response = $this->assign($orderId, [$this->courier2Id]);

        $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
        $this->assertSame(2, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function shift_couriers_exposes_busy_flag_and_active_count()
    {
        $this->enableCourierAssignmentModule(true, false);

        // courier1 везёт заказ, courier2 свободен
        $orderId = $this->insertOrder();
        $this->assign($orderId, [$this->courier1Id])
            ->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);

        $this->actingAs($this->collector, 'api');
        $response = $this->graphQL('{ shift_couriers { id is_busy active_delivery_orders_count } }');

        $couriers = collect($response->json('data.shift_couriers'))->keyBy('id');

        // Занятый курьер остаётся в выдаче — экран показывает его как «На заказе»
        $this->assertTrue($couriers[$this->courier1Id]['is_busy']);
        $this->assertSame(1, $couriers[$this->courier1Id]['active_delivery_orders_count']);
        $this->assertFalse($couriers[$this->courier2Id]['is_busy']);
        $this->assertSame(0, $couriers[$this->courier2Id]['active_delivery_orders_count']);
    }

    /** @test */
    public function delivered_order_releases_courier()
    {
        $this->enableCourierAssignmentModule(true, false);
        $orderId = $this->insertOrder();
        $this->assign($orderId, [$this->courier1Id]);

        \DB::table('delivery_orders')->where('order_id', $orderId)->update([
            'status_id' => Status::DELIVERY_FINISHED,
            'finished_at' => now(),
        ]);

        $this->assertFalse(User::findOrFail($this->courier1Id)->isBusy());
    }

    /** @test */
    public function courier_who_left_shift_is_rejected_with_readable_message()
    {
        $this->enableCourierAssignmentModule(true, false);
        $orderId = $this->insertOrder();

        // курьер закрыл смену, пока у сборщика открыт экран выбора
        \DB::table('shifts')->where('user_id', $this->courier1Id)->update(['finished_at' => now()]);

        $response = $this->assign($orderId, [$this->courier1Id]);

        $this->assertStringContainsString(
            'не на смене в этом магазине',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function assignment_by_sub_order_id_lands_on_root_order()
    {
        $this->enableCourierAssignmentModule(true, false);
        $parentId = $this->insertOrder();
        $subId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 1]);

        $response = $this->assign($subId, [$this->courier1Id]);

        $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
        // Назначение легло на корневой заказ, а не на подзаказ
        $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $parentId)->count());
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $subId)->count());
        $this->assertSame(1, (int) Order::find($parentId)->couriers_count);
        // Счётчик подзаказа резолвится с корня — иначе на нём горел бы тег «Ожидает курьера»
        $this->assertSame(1, Order::find($subId)->resolveCouriersCount());
    }

    /** @test */
    public function order_exposes_assigned_couriers()
    {
        $this->enableCourierAssignmentModule(true, true);
        $orderId = $this->insertOrder(['is_heavy' => 1]);
        $this->assign($orderId, [$this->courier1Id, $this->courier2Id]);

        $this->actingAs($this->collector, 'api');
        $response = $this->graphQL(
            'query ($id: Int) { order(id: $id) { couriers_count couriers { id } } }',
            ['id' => $orderId]
        );

        $ids = collect($response->json('data.order.couriers'))->pluck('id')->sort()->values()->all();

        $this->assertSame(2, $response->json('data.order.couriers_count'));
        $this->assertSame([$this->courier1Id, $this->courier2Id], $ids);
    }

    /** @test */
    public function cancelled_assignment_drops_courier_from_list()
    {
        $this->enableCourierAssignmentModule(true, true);
        $orderId = $this->insertOrder(['is_heavy' => 1]);
        $this->assign($orderId, [$this->courier1Id, $this->courier2Id]);

        // курьер отказался от доставки
        $delivery = \DB::table('deliveries')->where('user_id', $this->courier2Id)->first();
        \DB::table('delivery_orders')
            ->where('order_id', $orderId)->where('delivery_id', $delivery->id)
            ->update(['status_id' => Status::DELIVERY_CANCELED, 'finished_at' => now()]);
        \App\Support\JointDelivery::recalcCouriersCount(Order::findOrFail($orderId));

        $couriers = (new \App\GraphQL\Queries\OrderCouriersResolver())(Order::findOrFail($orderId));

        // Состав списка обязан совпадать со счётчиком
        $this->assertSame([$this->courier1Id], $couriers->pluck('id')->all());
        $this->assertSame(1, (int) Order::find($orderId)->couriers_count);
    }

    /** @test */
    public function mutation_rejected_when_both_modules_disabled()
    {
        $this->enableCourierAssignmentModule(false, false);
        $orderId = $this->insertOrder(['is_heavy' => 0]);

        $response = $this->assign($orderId, [$this->courier1Id]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertStringContainsString(
            'Назначение курьеров отключено',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }
}
