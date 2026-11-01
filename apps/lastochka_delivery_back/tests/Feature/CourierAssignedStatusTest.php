<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryStart;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Модуль courier_assignment: назначение курьера НЕ стартует рейс, заказ
 * остаётся «Назначен курьер» (ASSEMBLED + delivery_order WAIT_TO_START)
 * до DeliveryStart курьером.
 * Спека: docs/superpowers/specs/2026-07-18-courier-assigned-status-design.md
 */
class CourierAssignedStatusTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $collector;
    private int $courier1Id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(FcmNotificationService::class)
            ->shouldReceive('sendToUser')->andReturnNull();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'CAS shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->collector = $this->makeStaff('collector');
        $this->courier1Id = $this->makeStaff('courier')->id;
    }

    private function makeStaff(string $roleName): User
    {
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
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'cas-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function setModules(bool $courierAssignment, bool $heavy = false): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode([
                'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $courierAssignment],
                'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => $heavy],
            ]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private const MUTATION = '
        mutation DeliveryAssignCouriers($order_id: Int!, $courier_ids: [Int!]!) {
            DeliveryAssignCouriers(order_id: $order_id, courier_ids: $courier_ids) { code message }
        }
    ';

    private function assign(int $orderId, array $courierIds)
    {
        $this->actingAs($this->collector, 'api');

        return $this->graphQL(self::MUTATION, ['order_id' => $orderId, 'courier_ids' => $courierIds]);
    }

    /** @test */
    public function assignment_with_flag_keeps_order_assembled_and_trip_not_started()
    {
        $this->setModules(courierAssignment: true);
        $orderId = $this->insertOrder();

        $this->assign($orderId, [$this->courier1Id])
            ->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);

        $deliveryOrder = \DB::table('delivery_orders')->where('order_id', $orderId)->first();
        $this->assertNotNull($deliveryOrder);
        $this->assertSame(Status::DELIVERY_WAIT_TO_START, (int) $deliveryOrder->status_id);
        $this->assertNull(\DB::table('deliveries')->where('id', $deliveryOrder->delivery_id)->value('started_at'));
        $this->assertSame(Status::ORDER_ASSEMBLED, (int) Order::find($orderId)->status_id);
    }

    /** @test */
    public function rest_admin_assignment_with_flag_keeps_order_assembled()
    {
        $this->setModules(courierAssignment: true);
        config(['services.system.token' => 'test-system-token']);
        $orderId = $this->insertOrder();

        $response = $this->postJson('/backend-delivery/order/delivery', [
            'token' => 'test-system-token',
            'order_id' => $orderId,
            'courier_id' => $this->courier1Id,
        ]);

        $response->assertOk();
        $deliveryOrder = \DB::table('delivery_orders')->where('order_id', $orderId)->first();
        $this->assertNotNull($deliveryOrder);
        $this->assertNull(\DB::table('deliveries')->where('id', $deliveryOrder->delivery_id)->value('started_at'));
        $this->assertSame(Status::ORDER_ASSEMBLED, (int) Order::find($orderId)->status_id);
    }

    /** @test */
    public function delivery_start_moves_assigned_order_to_in_delivery()
    {
        $this->setModules(courierAssignment: true);
        $orderId = $this->insertOrder();
        $this->assign($orderId, [$this->courier1Id])
            ->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);

        // Старт рейса курьером: тот же static, который зовёт мутация DeliveryStart
        $delivery = Delivery::whereUserId($this->courier1Id)->first();
        $deliveryOrder = $delivery->deliveryOrders()
            ->where('status_id', Status::DELIVERY_WAIT_TO_START)->orderBy('priority')->first();
        DeliveryStart::deliveryStart($delivery, $deliveryOrder);

        $this->assertSame(Status::ORDER_IN_DELIVERY, (int) Order::find($orderId)->status_id);
        $this->assertNotNull($delivery->fresh()->started_at);
    }
}
