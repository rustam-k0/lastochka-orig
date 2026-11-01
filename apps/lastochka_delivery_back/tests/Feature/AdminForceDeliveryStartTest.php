<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryStart;
use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-242: кнопка «В доставке» в админке при включённом courier_assignment.
 *
 * Флаг start_delivery в REST /backend-delivery/order/delivery — явное намерение
 * админа провести доставку (шлёт ТОЛЬКО purchase_back OrderGoToNextStep):
 * назначение стартует рейс даже при включённом модуле. Без флага семантика
 * прежняя: при модуле назначение не стартует (флоу сборщика DeliveryAssignCouriers
 * и доп. курьер OrderCourierAdd), старт за курьером.
 */
class AdminForceDeliveryStartTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
    private int $adminId;
    private int $courierId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.system.token' => 'test-token']);

        $this->mock(FcmNotificationService::class)
            ->shouldReceive('sendToUser')->andReturnNull();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'AFDS shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->adminId = $this->makeStaff('admin')->id;
        $this->courierId = $this->makeStaff('courier')->id;
    }

    private function makeStaff(string $roleName): User
    {
        $userId = \DB::table('users')->insertGetId([
            'name' => ucfirst($roleName), 'surname' => 'Форс ' . uniqid(),
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
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'afds-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function enableCourierAssignment(): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode([
                'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => true],
            ]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function postDelivery(int $orderId, array $extra = [])
    {
        return $this->postJson('/backend-delivery/order/delivery', array_merge([
            'token' => 'test-token',
            'order_id' => $orderId,
            'courier_id' => $this->courierId,
            'admin_id' => $this->adminId,
        ], $extra));
    }

    /** @test */
    public function start_delivery_flag_starts_trip_despite_module()
    {
        $this->enableCourierAssignment();
        $orderId = $this->insertOrder();

        $this->postDelivery($orderId, ['start_delivery' => true])->assertOk();

        $assignment = \DB::table('delivery_orders')->where('order_id', $orderId)->first();
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $assignment->status_id);
        $this->assertNotNull($assignment->started_at);
        $this->assertNotNull(\DB::table('deliveries')->where('id', $assignment->delivery_id)->value('started_at'));
        $this->assertSame(Status::ORDER_IN_DELIVERY, (int) \DB::table('orders')->where('id', $orderId)->value('status_id'));
    }

    /** @test */
    public function start_delivery_flag_starts_trip_of_already_assigned_courier()
    {
        $this->enableCourierAssignment();
        $orderId = $this->insertOrder();

        // Курьер уже назначен ранее (сборщиком или прошлым кликом админа), рейс ждёт
        DeliveryTakeOrder::takeOrder($this->courierId, $orderId, $this->adminId);
        $this->assertSame(
            Status::ORDER_ASSEMBLED,
            (int) \DB::table('orders')->where('id', $orderId)->value('status_id')
        );

        $this->postDelivery($orderId, ['start_delivery' => true])->assertOk();

        $assignment = \DB::table('delivery_orders')->where('order_id', $orderId)->first();
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $assignment->status_id);
        $this->assertSame(Status::ORDER_IN_DELIVERY, (int) \DB::table('orders')->where('id', $orderId)->value('status_id'));
        // Назначение не задублировано
        $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function assignment_without_flag_keeps_order_assembled_when_module_enabled()
    {
        $this->enableCourierAssignment();
        $orderId = $this->insertOrder();

        $this->postDelivery($orderId)->assertOk();

        $assignment = \DB::table('delivery_orders')->where('order_id', $orderId)->first();
        $this->assertSame(Status::DELIVERY_WAIT_TO_START, (int) $assignment->status_id);
        $this->assertSame(Status::ORDER_ASSEMBLED, (int) \DB::table('orders')->where('id', $orderId)->value('status_id'));
    }

    /** @test */
    public function force_start_does_not_break_queue_when_trip_in_progress()
    {
        $this->enableCourierAssignment();

        // Курьер уже везёт первый заказ
        $firstOrderId = $this->insertOrder();
        $data = DeliveryTakeOrder::takeOrder($this->courierId, $firstOrderId, $this->adminId);
        DeliveryStart::deliveryStart($data['delivery'], $data['delivery_order']);

        // Второй заказ «В доставке» от админа — в хвост очереди, не параллельный старт
        $secondOrderId = $this->insertOrder();
        $this->postDelivery($secondOrderId, ['start_delivery' => true])->assertOk();

        $second = \DB::table('delivery_orders')->where('order_id', $secondOrderId)->first();
        $this->assertSame(Status::DELIVERY_WAIT_TO_START, (int) $second->status_id);
        $this->assertSame(Status::ORDER_ASSEMBLED, (int) \DB::table('orders')->where('id', $secondOrderId)->value('status_id'));

        $first = \DB::table('delivery_orders')->where('order_id', $firstOrderId)->first();
        $this->assertTrue((int) $second->priority > (int) $first->priority);
    }
}
