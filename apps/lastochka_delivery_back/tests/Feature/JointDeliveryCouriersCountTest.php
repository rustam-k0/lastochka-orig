<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\Models\Order;
use App\Models\Status;
use App\Support\JointDelivery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class JointDeliveryCouriersCountTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Joint shop', 'id_1c' => random_int(900000, 999999), 'default' => 0,
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
            'shop_id' => $this->shopId,
            'client_id' => $this->clientId,
            'uuid' => 'jd-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED,
            'amount' => 100,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function insertCourier(string $name): int
    {
        return \DB::table('users')->insertGetId([
            'name' => $name, 'surname' => 'Курьеров', 'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test */
    public function take_order_updates_root_couriers_count()
    {
        $orderId = $this->insertOrder(['is_heavy' => 1]);
        $c1 = $this->insertCourier('Первый');
        $c2 = $this->insertCourier('Второй');

        DeliveryTakeOrder::takeOrder($c1, $orderId);
        $this->assertSame(1, (int) Order::find($orderId)->couriers_count);

        DeliveryTakeOrder::takeOrder($c2, $orderId);
        $this->assertSame(2, (int) Order::find($orderId)->couriers_count);
    }

    /** @test */
    public function recalc_counts_unique_couriers_across_children_on_root()
    {
        $rootId = $this->insertOrder();
        $childId = $this->insertOrder(['parent_order_id' => $rootId, 'sub_number' => 1]);
        $c1 = $this->insertCourier('Общий');

        // Один и тот же курьер на root и на child = 1 уникальный
        DeliveryTakeOrder::takeOrder($c1, $rootId);
        // takeOrder блокирует sub-order — эмулируем назначение на child напрямую
        $deliveryId = \DB::table('deliveries')->where('user_id', $c1)->value('id');
        \DB::table('delivery_orders')->insert([
            'order_id' => $childId, 'delivery_id' => $deliveryId,
            'status_id' => Status::DELIVERY_WAIT_TO_START, 'priority' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        JointDelivery::recalcCouriersCount(Order::find($childId));

        $this->assertSame(1, (int) Order::find($rootId)->couriers_count);
        // Счётчик живёт только на корне
        $this->assertSame(0, (int) Order::find($childId)->couriers_count);
    }

    /** @test */
    public function canceled_assignments_are_not_counted()
    {
        $orderId = $this->insertOrder(['is_heavy' => 1]);
        $c1 = $this->insertCourier('Отменённый');
        $c2 = $this->insertCourier('Активный');

        DeliveryTakeOrder::takeOrder($c1, $orderId);
        DeliveryTakeOrder::takeOrder($c2, $orderId);
        \DB::table('delivery_orders')
            ->where('order_id', $orderId)
            ->whereIn('delivery_id', \DB::table('deliveries')->where('user_id', $c1)->pluck('id'))
            ->update(['status_id' => Status::DELIVERY_CANCELED]);

        JointDelivery::recalcCouriersCount(Order::find($orderId));

        $this->assertSame(1, (int) Order::find($orderId)->couriers_count);
    }

    /** @test */
    public function finished_assignments_are_still_counted()
    {
        // Историческое значение: завершённые назначения учитываются
        $orderId = $this->insertOrder(['is_heavy' => 1]);
        $c1 = $this->insertCourier('Довёзший');

        DeliveryTakeOrder::takeOrder($c1, $orderId);
        \DB::table('delivery_orders')->where('order_id', $orderId)
            ->update(['status_id' => Status::DELIVERY_FINISHED, 'finished_at' => now()]);

        JointDelivery::recalcCouriersCount(Order::find($orderId));

        $this->assertSame(1, (int) Order::find($orderId)->couriers_count);
    }
}
