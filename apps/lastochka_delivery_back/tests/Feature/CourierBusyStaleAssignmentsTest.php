<?php

namespace Tests\Feature;

use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * MID-243: зависшие назначения держали курьера «На заказе» навсегда.
 *
 * Занятость (User::isBusy / shift_couriers) обязана считаться по
 * DeliveryOrder::scopeActive: активный статус И finished_at IS NULL И
 * существующий заказ. Хвосты образуются двумя путями:
 *  - admin_back-консьюмер при отмене/завершении заказа закрывает назначения
 *    ТОЛЬКО штампом finished_at, не меняя status_id;
 *  - заказ исчезает (EntityDelete в админке, чистка dev-БД), а назначение
 *    остаётся в активном статусе без finished_at.
 */
class CourierBusyStaleAssignmentsTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $collector;
    private User $courier;

    protected function setUp(): void
    {
        parent::setUp();

        // FCM не должен ходить наружу (REST cancel шлёт пуш закрытым курьерам)
        $this->mock(FcmNotificationService::class)
            ->shouldReceive('sendToUser')->andReturnNull();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Stale shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
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
        $this->courier = $this->makeStaff('courier');
    }

    private function makeStaff(string $roleName): User
    {
        // User полностью guarded — сырой insert (паттерн DeliveryAssignCouriersTest)
        $userId = \DB::table('users')->insertGetId([
            'name' => ucfirst($roleName), 'surname' => 'Хвостовой ' . uniqid(),
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
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'cbs-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** Рейс курьера + назначение на заказ. Возвращает id строки delivery_orders. */
    private function insertAssignment(int $orderId, array $attrs = []): int
    {
        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $this->courier->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return \DB::table('delivery_orders')->insertGetId(array_merge([
            'order_id' => $orderId, 'delivery_id' => $deliveryId,
            'status_id' => Status::DELIVERY_WAIT_TO_START, 'priority' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** @test */
    public function active_assignment_marks_courier_busy()
    {
        $this->insertAssignment($this->insertOrder());

        $this->assertTrue(User::findOrFail($this->courier->id)->isBusy());
    }

    /** @test */
    public function finished_at_stamp_releases_courier_even_with_active_status()
    {
        // admin_back-консьюмер закрывает назначения ТОЛЬКО finished_at, статус не меняет
        $this->insertAssignment(
            $this->insertOrder(['status_id' => Status::ORDER_CANCELLED]),
            ['finished_at' => now()]
        );

        $this->assertFalse(User::findOrFail($this->courier->id)->isBusy());
    }

    /** @test */
    public function assignment_on_soft_deleted_order_releases_courier()
    {
        // EntityDelete в админке ставит заказу deleted_at, назначения не трогает
        $orderId = $this->insertOrder();
        $this->insertAssignment($orderId, ['status_id' => Status::DELIVERY_ARRIVED]);
        \DB::table('orders')->where('id', $orderId)->update(['deleted_at' => now()]);

        $this->assertFalse(User::findOrFail($this->courier->id)->isBusy());
    }

    /** @test */
    public function assignment_on_missing_order_releases_courier()
    {
        // Чистка dev-БД: заказ удалён физически (с FOREIGN_KEY_CHECKS=0 — FK
        // delivery_orders_order_id_foreign иначе не даст), назначение осталось.
        // Ровно так на dev появились назначения-сироты (заказ 77, MID-243).
        $orderId = $this->insertOrder();
        $this->insertAssignment($orderId, ['status_id' => Status::DELIVERY_ARRIVED]);
        \DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            \DB::table('orders')->where('id', $orderId)->delete();
        } finally {
            \DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->assertFalse(User::findOrFail($this->courier->id)->isBusy());
    }

    /** @test */
    public function shift_couriers_active_count_ignores_stale_assignments()
    {
        // Два хвоста + одно живое назначение → счётчик = 1, курьер busy
        $this->insertAssignment(
            $this->insertOrder(['status_id' => Status::ORDER_CANCELLED]),
            ['finished_at' => now()]
        );
        $staleOrderId = $this->insertOrder();
        $this->insertAssignment($staleOrderId, ['status_id' => Status::DELIVERY_ARRIVED]);
        \DB::table('orders')->where('id', $staleOrderId)->update(['deleted_at' => now()]);

        $this->insertAssignment($this->insertOrder());

        $this->actingAs($this->collector, 'api');
        $response = $this->graphQL('{ shift_couriers { id is_busy active_delivery_orders_count } }');

        $row = collect($response->json('data.shift_couriers'))->keyBy('id')[$this->courier->id];
        $this->assertTrue($row['is_busy']);
        $this->assertSame(1, $row['active_delivery_orders_count']);
    }

    /** @test */
    public function rest_cancel_closes_wait_to_start_assignment_on_cancelled_order()
    {
        config(['services.system.token' => 'test-token']);

        // Как при отмене из админки: purchase_back уже перевёл заказ в CANCELLED
        // до вызова REST-эндпоинта, назначение WAIT_TO_START (флоу courier_assignment)
        $orderId = $this->insertOrder(['status_id' => Status::ORDER_CANCELLED, 'couriers_count' => 1]);
        $doId = $this->insertAssignment($orderId);

        $response = $this->postJson('/backend-delivery/order/cancel', [
            'token' => 'test-token',
            'order_id' => $orderId,
        ]);

        $response->assertOk();

        $assignment = DeliveryOrder::withTrashed()->findOrFail($doId);
        $this->assertSame(Status::DELIVERY_CANCELED, (int) $assignment->status_id);
        $this->assertNotNull($assignment->finished_at);

        $this->assertFalse(User::findOrFail($this->courier->id)->isBusy());
        $this->assertSame(0, (int) Order::findOrFail($orderId)->couriers_count);
    }

    /** @test */
    public function rest_cancel_closes_assignment_on_assembled_order()
    {
        config(['services.system.token' => 'test-token']);

        // Отмена, пришедшая ДО перевода статуса (сторонний вызов): заказ ещё «Собран»
        $orderId = $this->insertOrder(['couriers_count' => 1]);
        $doId = $this->insertAssignment($orderId);

        $this->postJson('/backend-delivery/order/cancel', [
            'token' => 'test-token',
            'order_id' => $orderId,
        ])->assertOk();

        $assignment = DeliveryOrder::withTrashed()->findOrFail($doId);
        $this->assertSame(Status::DELIVERY_CANCELED, (int) $assignment->status_id);
        $this->assertFalse(User::findOrFail($this->courier->id)->isBusy());
    }
}
