<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Order\OrderCollectorUpdate;
use App\GraphQL\Mutations\Order\OrderPrepare;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryCollectorAssignedTest extends TestCase
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
            'title' => 'Collector history shop',
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
            'uuid' => 'hca-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_CREATED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_first_assignment_logs_collector_assigned_with_null_old_value(): void
    {
        $adminId = $this->makeUser('Админ', 'Первый');
        $collectorId = $this->makeUser('Сборщик', 'Первый');
        $orderId = $this->insertOrder();

        OrderPrepare::orderPrepare(
            Order::with('orderItems')->findOrFail($orderId),
            $collectorId,
            $adminId
        );

        $row = OrderHistory::where('order_id', $orderId)->where('action', OrderHistory::ACTION_COLLECTOR_ASSIGNED)->first();
        $this->assertNotNull($row);
        $this->assertSame($adminId, $row->actor_id);
        $this->assertNull($row->old_value);
        $this->assertStringContainsString('Первый', $row->new_value);
    }

    public function test_reassignment_via_order_collector_update_logs_old_and_new_collector(): void
    {
        $adminId = $this->makeUser('Админ', 'Второй');
        $firstCollectorId = $this->makeUser('Сборщиков', 'Иван');
        $secondCollectorId = $this->makeUser('Сборщиков', 'Пётр');
        $orderId = $this->insertOrder();

        OrderPrepare::orderPrepare(Order::with('orderItems')->findOrFail($orderId), $firstCollectorId, $adminId);

        \Auth::shouldReceive('id')->andReturn($adminId);
        // Order::orderItems() дергает auth()->user() для courier-фильтра (не связано с этим тестом);
        // строгий Mockery-мок фасада требует явного стаба для каждого вызванного метода.
        \Auth::shouldReceive('user')->andReturn(null);

        (new OrderCollectorUpdate())->__invoke(null, [
            'id' => $orderId,
            'collector_id' => $secondCollectorId,
        ]);

        $rows = OrderHistory::where('order_id', $orderId)
            ->where('action', OrderHistory::ACTION_COLLECTOR_ASSIGNED)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->old_value);
        $this->assertStringContainsString('Иван', $rows[1]->old_value);
        $this->assertStringContainsString('Пётр', $rows[1]->new_value);
    }
}
