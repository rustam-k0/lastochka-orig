<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryArrived;
use App\GraphQL\Mutations\Delivery\DeliveryFinish;
use App\GraphQL\Mutations\Delivery\DeliveryStart;
use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\GraphQL\Mutations\Order\OrderCancelByCourier;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Status;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MID-268: старт доставки одним курьером тяжёлого заказа переводит в «В пути»
 * и назначения соисполнителей — заказ они везут вместе.
 *
 * До фикса второй курьер висел в «В ожидании» до тех пор, пока сам не нажимал
 * «Поехали», хотя доставка уже шла. Re-test: синхронизация терялась, когда
 * очередь до тяжёлого заказа доходила через продвижение маршрута (после
 * доставки/отмены предыдущего), а «Приехал» соисполнителю не ставился.
 */
class JointDeliveryStartTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $fcm = $this->mock(FcmNotificationService::class);
        $fcm->shouldReceive('sendToUser')->andReturnNull();
        $fcm->shouldReceive('sendToUsers')->andReturnNull();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Start shop', 'id_1c' => random_int(900000, 999999), 'default' => 0,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertOrder(array $attrs = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'jds-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100, 'is_heavy' => 1,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function insertCourier(string $name): int
    {
        return \DB::table('users')->insertGetId([
            'name' => $name, 'surname' => 'Курьеров', 'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Оба курьера назначены на заказ, доставку никто ещё не начал.
     *
     * @return array{0:int,1:int,2:array,3:array}
     */
    private function assignedFixture(int $orderId): array
    {
        $c1 = $this->insertCourier('Первый');
        $c2 = $this->insertCourier('Второй');

        $d1 = DeliveryTakeOrder::takeOrder($c1, $orderId);
        $d2 = DeliveryTakeOrder::takeOrder($c2, $orderId);

        return [$c1, $c2, $d1, $d2];
    }

    /** @test */
    public function start_by_one_courier_moves_co_executor_to_in_progress()
    {
        $orderId = $this->insertOrder();
        [, , $d1, $d2] = $this->assignedFixture($orderId);

        $this->assertSame(Status::DELIVERY_WAIT_TO_START, (int) $d2['delivery_order']->fresh()->status_id);

        DeliveryStart::deliveryStart($d1['delivery'], $d1['delivery_order']);

        $do2 = $d2['delivery_order']->fresh();
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $do2->status_id, 'Соисполнитель тоже «В пути»');
        $this->assertNotNull($do2->started_at);
        $this->assertNotNull(Delivery::withoutGlobalScopes()->find($do2->delivery_id)->started_at);

        $this->assertSame(Status::ORDER_IN_DELIVERY, (int) Order::find($orderId)->status_id);
    }

    /** @test */
    public function co_executor_with_unstarted_own_order_is_advanced()
    {
        // У второго курьера есть свой заказ раньше по приоритету, но он его ещё
        // не повёз: joint-заказ физически едет с коллегой — значит, и у второго
        // он «В пути». Свой заказ остаётся ждать своей очереди.
        $earlierOrderId = $this->insertOrder(['is_heavy' => 0]);
        $c2 = $this->insertCourier('Второй');
        $earlier = DeliveryTakeOrder::takeOrder($c2, $earlierOrderId);
        $earlier['delivery_order']->update(['priority' => 1]);

        $orderId = $this->insertOrder();
        $c1 = $this->insertCourier('Первый');
        $d1 = DeliveryTakeOrder::takeOrder($c1, $orderId);
        $d2 = DeliveryTakeOrder::takeOrder($c2, $orderId);
        $d2['delivery_order']->update(['priority' => 2]);

        DeliveryStart::deliveryStart($d1['delivery'], $d1['delivery_order']);

        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $d2['delivery_order']->fresh()->status_id);
        $this->assertSame(Status::DELIVERY_WAIT_TO_START, (int) $earlier['delivery_order']->fresh()->status_id);
    }

    /** @test */
    public function co_executor_busy_with_order_in_progress_is_not_advanced()
    {
        // Второй курьер уже везёт другой заказ — принудительный старт joint-заказа
        // дал бы ему два «В пути» и сбил маршрут. Стартует сам, когда доедет.
        $busyOrderId = $this->insertOrder(['is_heavy' => 0]);
        $c2 = $this->insertCourier('Второй');
        $busy = DeliveryTakeOrder::takeOrder($c2, $busyOrderId);
        $busy['delivery_order']->update(['priority' => 1]);
        DeliveryStart::deliveryStart($busy['delivery'], $busy['delivery_order']);

        $orderId = $this->insertOrder();
        $c1 = $this->insertCourier('Первый');
        $d1 = DeliveryTakeOrder::takeOrder($c1, $orderId);
        $d2 = DeliveryTakeOrder::takeOrder($c2, $orderId);
        $d2['delivery_order']->update(['priority' => 2]);

        DeliveryStart::deliveryStart($d1['delivery'], $d1['delivery_order']);

        $this->assertSame(Status::DELIVERY_WAIT_TO_START, (int) $d2['delivery_order']->fresh()->status_id);
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $busy['delivery_order']->fresh()->status_id);
    }

    /** @test */
    public function co_executor_without_priorities_is_advanced_for_any_joint_order()
    {
        // Re-test MID-268: у соисполнителя два тяжёлых заказа, приоритеты не
        // выбраны. Коллега стартует НЕ первый из них — у соисполнителя он всё
        // равно должен стать «В пути».
        $firstOrderId = $this->insertOrder();
        $secondOrderId = $this->insertOrder();
        $c1 = $this->insertCourier('Первый');
        $c2 = $this->insertCourier('Второй');

        DeliveryTakeOrder::takeOrder($c2, $firstOrderId);
        $d2second = DeliveryTakeOrder::takeOrder($c2, $secondOrderId);
        $d1second = DeliveryTakeOrder::takeOrder($c1, $secondOrderId);

        DeliveryStart::deliveryStart($d1second['delivery'], $d1second['delivery_order']);

        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $d2second['delivery_order']->fresh()->status_id);
    }

    /** @test */
    public function route_advance_after_finish_syncs_co_executor()
    {
        // Re-test MID-268: первый курьер повёз свой заказ, довёз, очередь дошла
        // до тяжёлого — соисполнитель должен увидеть «В пути», а не «В ожидании».
        $ownOrderId = $this->insertOrder(['is_heavy' => 0]);
        $jointOrderId = $this->insertOrder();
        $c1 = $this->insertCourier('Первый');
        $c2 = $this->insertCourier('Второй');

        $own = DeliveryTakeOrder::takeOrder($c1, $ownOrderId);
        $own['delivery_order']->update(['priority' => 1]);
        $d1 = DeliveryTakeOrder::takeOrder($c1, $jointOrderId);
        $d1['delivery_order']->update(['priority' => 2]);
        $d2 = DeliveryTakeOrder::takeOrder($c2, $jointOrderId);

        DeliveryStart::deliveryStart($own['delivery'], $own['delivery_order']);
        $this->assertSame(Status::DELIVERY_WAIT_TO_START, (int) $d2['delivery_order']->fresh()->status_id);

        DeliveryFinish::deliveryFinish($own['delivery_order']->fresh(), $own['delivery']);

        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $d1['delivery_order']->fresh()->status_id);
        $do2 = $d2['delivery_order']->fresh();
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $do2->status_id, 'Соисполнитель тоже «В пути»');
        $this->assertNotNull($do2->started_at);
        $this->assertNotNull(Delivery::withoutGlobalScopes()->find($do2->delivery_id)->started_at);
    }

    /** @test */
    public function route_advance_after_courier_cancel_syncs_co_executor()
    {
        $ownOrderId = $this->insertOrder(['is_heavy' => 0]);
        $jointOrderId = $this->insertOrder();
        $c1 = $this->insertCourier('Первый');
        $c2 = $this->insertCourier('Второй');

        $own = DeliveryTakeOrder::takeOrder($c1, $ownOrderId);
        $own['delivery_order']->update(['priority' => 1]);
        $d1 = DeliveryTakeOrder::takeOrder($c1, $jointOrderId);
        $d1['delivery_order']->update(['priority' => 2]);
        $d2 = DeliveryTakeOrder::takeOrder($c2, $jointOrderId);

        DeliveryStart::deliveryStart($own['delivery'], $own['delivery_order']);

        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);
        (new OrderCancelByCourier())->__invoke(null, [
            'delivery_order_id' => $own['delivery_order']->id,
            'reason' => 'Клиент недоступен',
        ]);

        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $d1['delivery_order']->fresh()->status_id);
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $d2['delivery_order']->fresh()->status_id);
    }

    /** @test */
    public function system_close_advance_syncs_co_executor_of_next_joint_order()
    {
        // Первый курьер довёз тяжёлый A; у второго A закрывается системно и
        // очередь переходит к тяжёлому B, который он везёт с третьим курьером —
        // у третьего B тоже должен стать «В пути».
        $orderAId = $this->insertOrder();
        $orderBId = $this->insertOrder();
        $c1 = $this->insertCourier('Первый');
        $c2 = $this->insertCourier('Второй');
        $c3 = $this->insertCourier('Третий');

        $a1 = DeliveryTakeOrder::takeOrder($c1, $orderAId);
        $a2 = DeliveryTakeOrder::takeOrder($c2, $orderAId);
        $a2['delivery_order']->update(['priority' => 1]);
        $b2 = DeliveryTakeOrder::takeOrder($c2, $orderBId);
        $b2['delivery_order']->update(['priority' => 2]);
        $b3 = DeliveryTakeOrder::takeOrder($c3, $orderBId);

        DeliveryStart::deliveryStart($a1['delivery'], $a1['delivery_order']);
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $a2['delivery_order']->fresh()->status_id);

        DeliveryFinish::deliveryFinish($a1['delivery_order']->fresh(), $a1['delivery']);

        $this->assertSame(Status::DELIVERY_FINISHED, (int) $a2['delivery_order']->fresh()->status_id);
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $b2['delivery_order']->fresh()->status_id);
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $b3['delivery_order']->fresh()->status_id, 'Третий курьер тоже «В пути»');
    }

    /** @test */
    public function arrival_by_one_courier_syncs_co_executor_in_progress()
    {
        $orderId = $this->insertOrder();
        [, , $d1, $d2] = $this->assignedFixture($orderId);
        DeliveryStart::deliveryStart($d1['delivery'], $d1['delivery_order']);

        DeliveryArrived::arrive($d1['delivery_order']->fresh());

        $do2 = $d2['delivery_order']->fresh();
        $this->assertSame(Status::DELIVERY_ARRIVED, (int) $do2->status_id, 'Соисполнитель тоже «Приехал»');
        $this->assertNotNull($do2->arrived_at);
    }

    /** @test */
    public function arrival_does_not_touch_waiting_co_executor()
    {
        // Соисполнитель ещё не выехал (занят другим заказом) — «Приехал» ему
        // ставить нельзя, он даже не «В пути».
        $busyOrderId = $this->insertOrder(['is_heavy' => 0]);
        $c2 = $this->insertCourier('Второй');
        $busy = DeliveryTakeOrder::takeOrder($c2, $busyOrderId);
        $busy['delivery_order']->update(['priority' => 1]);
        DeliveryStart::deliveryStart($busy['delivery'], $busy['delivery_order']);

        $orderId = $this->insertOrder();
        $c1 = $this->insertCourier('Первый');
        $d1 = DeliveryTakeOrder::takeOrder($c1, $orderId);
        $d2 = DeliveryTakeOrder::takeOrder($c2, $orderId);
        $d2['delivery_order']->update(['priority' => 2]);

        DeliveryStart::deliveryStart($d1['delivery'], $d1['delivery_order']);
        DeliveryArrived::arrive($d1['delivery_order']->fresh());

        $this->assertSame(Status::DELIVERY_WAIT_TO_START, (int) $d2['delivery_order']->fresh()->status_id);
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $busy['delivery_order']->fresh()->status_id);
    }

    /** @test */
    public function single_courier_order_start_is_unchanged()
    {
        $orderId = $this->insertOrder(['is_heavy' => 0]);
        $c1 = $this->insertCourier('Одиночка');
        $d1 = DeliveryTakeOrder::takeOrder($c1, $orderId);

        DeliveryStart::deliveryStart($d1['delivery'], $d1['delivery_order']);

        $do1 = $d1['delivery_order']->fresh();
        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $do1->status_id);
        $this->assertSame(Status::ORDER_IN_DELIVERY, (int) Order::find($orderId)->status_id);
    }

    /** @test */
    public function finished_assignment_of_co_executor_is_not_restarted()
    {
        $orderId = $this->insertOrder();
        [, , $d1, $d2] = $this->assignedFixture($orderId);

        $d2['delivery_order']->update([
            'status_id' => Status::DELIVERY_FINISHED,
            'finished_at' => now(),
        ]);

        DeliveryStart::deliveryStart($d1['delivery'], $d1['delivery_order']);

        $this->assertSame(Status::DELIVERY_FINISHED, (int) $d2['delivery_order']->fresh()->status_id);
    }
}
