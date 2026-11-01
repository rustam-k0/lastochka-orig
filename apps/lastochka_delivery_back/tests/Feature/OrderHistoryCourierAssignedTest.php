<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\GraphQL\Mutations\Order\OrderCourierUpdate;
use App\Models\OrderHistory;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryCourierAssignedTest extends TestCase
{
    use DatabaseTransactions;

    private int $clientId;
    private int $shopId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Courier history shop',
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
    }

    private function makeUser(string $name, string $surname): int
    {
        return \DB::table('users')->insertGetId([
            'name' => $name,
            'surname' => $surname,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrder(): int
    {
        return \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'hcu-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_first_assignment_logs_courier_assigned_with_null_old_value(): void
    {
        $adminId = $this->makeUser('Админ', 'Третий');
        $courierId = $this->makeUser('Курьеров', 'Первый');
        $orderId = $this->insertOrder();

        DeliveryTakeOrder::takeOrder($courierId, $orderId, $adminId);

        $row = OrderHistory::where('order_id', $orderId)->where('action', OrderHistory::ACTION_COURIER_ASSIGNED)->first();
        $this->assertNotNull($row);
        $this->assertSame($adminId, $row->actor_id);
        $this->assertNull($row->old_value);
        $this->assertStringContainsString('Первый', $row->new_value);
    }

    public function test_reassignment_via_order_courier_update_logs_old_and_new_courier(): void
    {
        $adminId = $this->makeUser('Админ', 'Четвёртый');
        $firstCourierId = $this->makeUser('Курьеров', 'Иван');
        $secondCourierId = $this->makeUser('Курьеров', 'Пётр');
        $orderId = $this->insertOrder();

        DeliveryTakeOrder::takeOrder($firstCourierId, $orderId, $adminId);

        \Auth::shouldReceive('id')->andReturn($adminId);

        (new OrderCourierUpdate())->__invoke(null, [
            'id' => $orderId,
            'courier_id' => $secondCourierId,
        ]);

        $rows = OrderHistory::where('order_id', $orderId)
            ->where('action', OrderHistory::ACTION_COURIER_ASSIGNED)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->old_value);
        $this->assertStringContainsString('Иван', $rows[1]->old_value);
        $this->assertStringContainsString('Пётр', $rows[1]->new_value);
    }
}
