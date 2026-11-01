<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Отменённый админом заказ обязан уходить из приложения курьера.
 *
 * JointDelivery::closeOtherAssignments закрывал назначение (статус +
 * finished_at), но рейс закрывал только в ветке «заказ был ТЕКУЩИМ у курьера»
 * (DELIVERY_IN_PROGRESS/ARRIVED). Во флоу courier_assignment назначение живёт в
 * DELIVERY_WAIT_TO_START (сборщик назначил, курьер ещё не выехал) — рейс
 * оставался открытым навсегда, а вместе с ним в списке курьера висел
 * отменённый заказ и занимал слот MAX_ORDERS_COUNT.
 */
class CanceledOrderClearsCourierRouteTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $courier;

    protected function setUp(): void
    {
        parent::setUp();

        // REST cancel шлёт пуш закрытым курьерам — наружу не ходим
        $this->mock(FcmNotificationService::class)
            ->shouldReceive('sendToUser')->andReturnNull();

        config(['services.system.token' => 'test-token']);

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Route shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // User полностью guarded — сырой insert (паттерн DeliveryAssignCouriersTest)
        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Courier', 'surname' => 'Маршрутный ' . uniqid(),
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->courier = User::findOrFail($courierId);
        $this->courier->assignRole(Role::findOrCreate(Role::COURIER, 'api'));

        \DB::table('shifts')->insert([
            'user_id' => $courierId, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertOrder(array $attrs = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'ccr-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function newRoute(array $attrs = []): int
    {
        return \DB::table('deliveries')->insertGetId(array_merge([
            'user_id' => $this->courier->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function addAssignment(int $deliveryId, int $orderId, array $attrs = []): int
    {
        return \DB::table('delivery_orders')->insertGetId(array_merge([
            'order_id' => $orderId, 'delivery_id' => $deliveryId,
            'status_id' => Status::DELIVERY_WAIT_TO_START, 'priority' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function cancelViaRest(int $orderId): void
    {
        $this->postJson('/backend-delivery/order/cancel', [
            'token' => 'test-token',
            'order_id' => $orderId,
        ])->assertOk();
    }

    /** Модуль courier_assignment: включён — курьер сам заказы не берёт */
    private function setCourierAssignmentModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            [
                'value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]),
                'type' => 'json', 'updated_at' => now(), 'created_at' => now(),
            ]
        );
    }

    /** @test */
    public function cancel_of_only_wait_to_start_assignment_closes_route()
    {
        // Заказ отменён из админки: purchase_back перевёл статус до REST-вызова
        $deliveryId = $this->newRoute();
        $orderId = $this->insertOrder(['status_id' => Status::ORDER_CANCELLED, 'couriers_count' => 1]);
        $doId = $this->addAssignment($deliveryId, $orderId);

        $this->cancelViaRest($orderId);

        $assignment = DeliveryOrder::withTrashed()->findOrFail($doId);
        $this->assertSame(Status::DELIVERY_CANCELED, (int) $assignment->status_id);
        $this->assertNotNull($assignment->finished_at);
        $this->assertNotNull($assignment->deleted_at, 'Отменённое назначение убирается из рейса soft-delete');

        $this->assertNotNull(
            \DB::table('deliveries')->where('id', $deliveryId)->value('finished_at'),
            'Рейс без незакрытых назначений обязан закрыться'
        );

        // Delivery под глобальным скоупом NotFinished — закрытый рейс курьеру не виден
        $this->actingAs($this->courier, 'api');
        $this->assertNull(Delivery::auth()->first());
    }

    /** @test */
    public function canceled_assignment_disappears_from_courier_orders_list()
    {
        $deliveryId = $this->newRoute();
        $canceledOrderId = $this->insertOrder(['status_id' => Status::ORDER_CANCELLED, 'couriers_count' => 1]);
        $liveOrderId = $this->insertOrder();

        $this->addAssignment($deliveryId, $canceledOrderId, ['priority' => 0]);
        $liveDoId = $this->addAssignment($deliveryId, $liveOrderId, ['priority' => 1]);

        $this->cancelViaRest($canceledOrderId);

        // Рейс продолжается — в нём остался живой заказ
        $this->assertNull(\DB::table('deliveries')->where('id', $deliveryId)->value('finished_at'));

        $this->actingAs($this->courier, 'api');
        $response = $this->graphQL('{ delivery_orders_paginate(first: 20) { data { id status_id order { id } } } }');

        $ids = collect($response->json('data.delivery_orders_paginate.data'))->pluck('id')->all();
        $this->assertSame([$liveDoId], $ids, 'Курьер видит только незакрытые назначения рейса');
    }

    /** @test */
    public function cancel_of_current_assignment_still_promotes_next_order()
    {
        $deliveryId = $this->newRoute(['started_at' => now()]);
        $currentOrderId = $this->insertOrder([
            'status_id' => Status::ORDER_CANCELLED, 'couriers_count' => 1,
        ]);
        $nextOrderId = $this->insertOrder();

        $this->addAssignment($deliveryId, $currentOrderId, [
            'priority' => 0, 'status_id' => Status::DELIVERY_IN_PROGRESS, 'started_at' => now(),
        ]);
        $nextDoId = $this->addAssignment($deliveryId, $nextOrderId, ['priority' => 1]);

        $this->cancelViaRest($currentOrderId);

        $next = DeliveryOrder::findOrFail($nextDoId);
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $next->status_id, 'Следующий заказ маршрута поехал');
        $this->assertSame(
            Status::ORDER_IN_DELIVERY,
            (int) \DB::table('orders')->where('id', $nextOrderId)->value('status_id')
        );
        $this->assertNull(
            \DB::table('deliveries')->where('id', $deliveryId)->value('finished_at'),
            'Рейс с незакрытым назначением не закрывается'
        );
    }

    /** @test */
    public function closed_assignments_do_not_consume_route_limit()
    {
        $this->setCourierAssignmentModule(false);

        // Рейс, забитый хвостами: доставленные и отменённые заказы слот не занимают
        $deliveryId = $this->newRoute();
        for ($i = 0; $i < Delivery::MAX_ORDERS_COUNT; $i++) {
            $this->addAssignment($deliveryId, $this->insertOrder(['status_id' => Status::ORDER_FINISHED]), [
                'priority' => $i,
                'status_id' => $i % 2 === 0 ? Status::DELIVERY_FINISHED : Status::DELIVERY_CANCELED,
                'finished_at' => now(),
            ]);
        }

        $freshOrderId = $this->insertOrder();

        $this->actingAs($this->courier, 'api');
        $response = $this->graphQL(
            'mutation Take($order_id: Int!) { DeliveryTakeOrder(order_id: $order_id) { code message } }',
            ['order_id' => $freshOrderId]
        );

        $response->assertJson(['data' => ['DeliveryTakeOrder' => ['code' => 200]]]);
        $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $freshOrderId)->count());
    }
}
