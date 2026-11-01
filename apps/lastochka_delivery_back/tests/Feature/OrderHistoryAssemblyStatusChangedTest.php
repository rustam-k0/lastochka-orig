<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\OrderHistory;
use App\Models\Role;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryAssemblyStatusChangedTest extends TestCase
{
    use DatabaseTransactions;

    public function test_finish_order_logs_status_changed_to_assembled(): void
    {
        // finishOrder() при успехе шлёт пуш всем курьерам магазина (notifyCouriersOrderReady) —
        // роль должна существовать, иначе Spatie::role() падает "no role named `courier`"
        // даже когда курьеров с этой ролью нет.
        Role::findOrCreate('courier', 'api');

        $clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shopId = \DB::table('shops')->insertGetId([
            'title' => 'Assembly status history shop',
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

        $collectorId = \DB::table('users')->insertGetId([
            'name' => 'Сборщиков',
            'surname' => 'Финишный',
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $shopId,
            'uuid' => 'ohasc-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $clientId,
            'status_id' => Status::ORDER_PREPARING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $collectorId,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AssemblyFinish::finishOrder([], Assembly::findOrFail($assemblyId));

        $row = OrderHistory::where('order_id', $orderId)
            ->where('action', OrderHistory::ACTION_STATUS_CHANGED)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_STAFF, $row->actor_type);
        $this->assertSame($collectorId, $row->actor_id);
        $this->assertStringContainsString('Финишный', $row->actor_name);
        $this->assertSame('Заказ на сборке', $row->old_value);
        $this->assertSame('Собрано', $row->new_value);

        $this->assertSame(Status::ORDER_ASSEMBLED, (int) \DB::table('orders')->where('id', $orderId)->value('status_id'));
    }
}
