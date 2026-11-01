<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryFinish;
use App\GraphQL\Mutations\Order\OrderCancelByCourier;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\OrderHistory;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderHistoryCourierCancelAndFinishTest extends TestCase
{
    use DatabaseTransactions;

    private int $clientId;
    private int $shopId;
    private int $courierId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Courier cancel/finish shop',
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

        $this->courierId = \DB::table('users')->insertGetId([
            'name' => 'Курьеров',
            'surname' => 'Тестовый',
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrder(int $statusId): int
    {
        return \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'ohccf-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => $statusId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_order_cancel_by_courier_logs_canceled_with_reason_comment(): void
    {
        $orderId = $this->insertOrder(Status::ORDER_IN_DELIVERY);
        $delivery = Delivery::create(['user_id' => $this->courierId]);
        $deliveryOrder = $delivery->deliveryOrders()->create([
            'order_id' => $orderId,
            'status_id' => Status::DELIVERY_IN_PROGRESS,
            'priority' => 1,
        ]);

        (new OrderCancelByCourier())->__invoke(null, [
            'delivery_order_id' => $deliveryOrder->id,
            'reason' => 'Клиент не отвечает',
        ]);

        $row = OrderHistory::where('order_id', $orderId)->where('action', OrderHistory::ACTION_CANCELED)->first();
        $this->assertNotNull($row);
        $this->assertSame('Клиент не отвечает', $row->comment);
    }

    public function test_delivery_finish_logs_delivery_completed(): void
    {
        $orderId = $this->insertOrder(Status::ORDER_IN_DELIVERY);
        $delivery = Delivery::create(['user_id' => $this->courierId]);
        $deliveryOrder = $delivery->deliveryOrders()->create([
            'order_id' => $orderId,
            'status_id' => Status::DELIVERY_IN_PROGRESS,
            'priority' => 1,
        ]);

        DeliveryFinish::deliveryFinish($deliveryOrder->fresh(), $delivery->fresh());

        $row = OrderHistory::where('order_id', $orderId)->where('action', OrderHistory::ACTION_DELIVERY_COMPLETED)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->courierId, $row->actor_id);
        $this->assertStringContainsString('Тестовый', $row->actor_name);
    }

    public function test_delivery_finish_logs_delivery_completed_for_child_orders(): void
    {
        $orderId = $this->insertOrder(Status::ORDER_IN_DELIVERY);
        $childId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'ohccf-child-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_ASSEMBLED,
            'parent_order_id' => $orderId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delivery = Delivery::create(['user_id' => $this->courierId]);
        $deliveryOrder = $delivery->deliveryOrders()->create([
            'order_id' => $orderId,
            'status_id' => Status::DELIVERY_IN_PROGRESS,
            'priority' => 1,
        ]);

        DeliveryFinish::deliveryFinish($deliveryOrder->fresh(), $delivery->fresh());

        $row = OrderHistory::where('order_id', $childId)->where('action', OrderHistory::ACTION_DELIVERY_COMPLETED)->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_SYSTEM, $row->actor_type);
        $this->assertSame('Синхронизировано с завершением доставки родителя', $row->comment);
        $this->assertSame(Status::ORDER_FINISHED, (int) \DB::table('orders')->where('id', $childId)->value('status_id'));
    }

    public function test_delivery_finish_logs_in_delivery_for_next_orders_child(): void
    {
        $orderId = $this->insertOrder(Status::ORDER_IN_DELIVERY);
        $nextOrderId = $this->insertOrder(Status::ORDER_ASSEMBLED);
        $nextChildId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'ohccf-nchild-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_ASSEMBLED,
            'parent_order_id' => $nextOrderId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delivery = Delivery::create(['user_id' => $this->courierId]);
        $deliveryOrder = $delivery->deliveryOrders()->create([
            'order_id' => $orderId,
            'status_id' => Status::DELIVERY_IN_PROGRESS,
            'priority' => 1,
        ]);
        $delivery->deliveryOrders()->create([
            'order_id' => $nextOrderId,
            'status_id' => Status::DELIVERY_WAIT_TO_START,
            'priority' => 2,
        ]);

        DeliveryFinish::deliveryFinish($deliveryOrder->fresh(), $delivery->fresh());

        $row = OrderHistory::where('order_id', $nextChildId)->where('action', OrderHistory::ACTION_STATUS_CHANGED)->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_SYSTEM, $row->actor_type);
        $this->assertSame('Синхронизировано с переходом родителя в доставку', $row->comment);
    }
}
