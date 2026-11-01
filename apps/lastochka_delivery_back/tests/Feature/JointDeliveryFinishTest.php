<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryFinish;
use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\Status;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class JointDeliveryFinishTest extends TestCase
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
            'title' => 'Finish shop', 'id_1c' => random_int(900000, 999999), 'default' => 0,
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
    }

    private function insertOrder(array $attrs = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'jdf-' . uniqid(),
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

    /** Оба курьера взяли заказ и стартовали доставку. @return array{int,int,DeliveryOrder,DeliveryOrder} */
    private function jointDeliveryFixture(int $orderId): array
    {
        $c1 = $this->insertCourier('Первый');
        $c2 = $this->insertCourier('Второй');

        $d1 = DeliveryTakeOrder::takeOrder($c1, $orderId);
        $d2 = DeliveryTakeOrder::takeOrder($c2, $orderId);

        // Оба рейса в пути
        foreach ([$d1, $d2] as $d) {
            $d['delivery']->update(['started_at' => now()]);
            $d['delivery_order']->update(['status_id' => Status::DELIVERY_IN_PROGRESS, 'started_at' => now()]);
        }
        Order::whereId($orderId)->update(['status_id' => Status::ORDER_IN_DELIVERY]);

        return [$c1, $c2, $d1['delivery_order']->fresh(), $d2['delivery_order']->fresh()];
    }

    /** @test */
    public function first_finisher_closes_order_and_system_closes_second_assignment()
    {
        $orderId = $this->insertOrder();
        [, , $do1, $do2] = $this->jointDeliveryFixture($orderId);

        DeliveryFinish::deliveryFinish($do1, $do1->delivery);

        $this->assertSame(Status::ORDER_FINISHED, (int) Order::find($orderId)->status_id);

        $do2->refresh();
        $this->assertSame(Status::DELIVERY_FINISHED, (int) $do2->status_id);
        $this->assertNotNull($do2->finished_at);

        // Рейс второго курьера завершён (других заказов в маршруте не было)
        $this->assertNotNull(Delivery::withoutGlobalScopes()->find($do2->delivery_id)->finished_at);
    }

    /** @test */
    public function system_close_advances_second_couriers_route()
    {
        $orderId = $this->insertOrder();
        [, $c2, $do1, $do2] = $this->jointDeliveryFixture($orderId);

        // У второго курьера в маршруте есть ещё один заказ следующим приоритетом.
        // delivery_id не в $fillable — создаём через relation, которая проставляет FK
        // сама (тот же путь, что DeliveryTakeOrder::takeOrder).
        $nextOrderId = $this->insertOrder(['is_heavy' => 0]);
        $c2Delivery = Delivery::withoutGlobalScopes()->find($do2->delivery_id);
        $nextDo = $c2Delivery->deliveryOrders()->create([
            'order_id' => $nextOrderId,
            'status_id' => Status::DELIVERY_WAIT_TO_START,
            'priority' => $do2->priority + 1,
        ]);

        DeliveryFinish::deliveryFinish($do1, $do1->delivery);

        $this->assertSame(Status::DELIVERY_IN_PROGRESS, (int) $nextDo->fresh()->status_id);
        $this->assertSame(Status::ORDER_IN_DELIVERY, (int) Order::find($nextOrderId)->status_id);
        $this->assertNull(Delivery::withoutGlobalScopes()->find($do2->delivery_id)->finished_at);
    }

    /** @test */
    public function courier_cancel_closes_all_other_assignments_as_cancelled()
    {
        $orderId = $this->insertOrder();
        [, , $do1, $do2] = $this->jointDeliveryFixture($orderId);

        // Второй курьер отменяет заказ целиком
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['message' => 'OK'], 200)]);
        (new \App\GraphQL\Mutations\Order\OrderCancelByCourier())->__invoke(null, [
            'delivery_order_id' => $do2->id,
            'reason' => 'Клиент недоступен',
        ]);

        $this->assertSame(Status::ORDER_CANCELLED, (int) Order::find($orderId)->status_id);
        $do1->refresh();
        $this->assertSame(Status::DELIVERY_CANCELED, (int) $do1->status_id);
        $this->assertNotNull($do1->finished_at);
    }

    /** @test */
    public function full_return_by_finishing_courier_cancels_order_and_recalculates_couriers_count()
    {
        $orderId = $this->insertOrder();
        [, , $do1, $do2] = $this->jointDeliveryFixture($orderId);

        $this->assertSame(2, (int) Order::find($orderId)->couriers_count);

        // Единственная позиция заказа полностью возвращена завершающим курьером
        // (returned_quantity === quantity) → applyCourierReturns вернёт $allReturned=true.
        $productId = \DB::table('products')->insertGetId([
            'title' => 'Full return product', 'articul' => 'ART-FR-' . uniqid(),
            'unit_type_id' => 1, 'info_weight' => 100, 'info_weight_type_id' => 2,
            'status_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId,
            'quantity' => 2, 'price' => 100, 'price_during_order' => 100,
            'returned_quantity' => 2,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DeliveryFinish::deliveryFinish($do1, $do1->delivery);

        $order = Order::find($orderId);
        $this->assertSame(Status::ORDER_CANCELLED, (int) $order->status_id);
        $do2->refresh();
        $this->assertSame(Status::DELIVERY_CANCELED, (int) $do2->status_id);

        // Bugfix: назначения обоих курьеров отменены — couriers_count должен
        // обнулиться, иначе клиент видит isJointDelivery=true на отменённом заказе.
        $this->assertSame(0, (int) $order->couriers_count);
    }

    /** @test */
    public function controller_cancel_processes_all_open_assignments()
    {
        $orderId = $this->insertOrder();
        [, , $do1, $do2] = $this->jointDeliveryFixture($orderId);

        Order::whereId($orderId)->update(['status_id' => Status::ORDER_IN_DELIVERY]);

        $response = $this->postJson('/backend-delivery/order/cancel', [
            'token' => config('services.system.token'),
            'order_id' => $orderId,
        ]);

        $response->assertOk();
        // Оба назначения обработаны: активных не осталось
        $open = DeliveryOrder::where('order_id', $orderId)
            ->whereNotIn('status_id', [Status::DELIVERY_FINISHED, Status::DELIVERY_CANCELED])
            ->count();
        $this->assertSame(0, $open);
    }
}
