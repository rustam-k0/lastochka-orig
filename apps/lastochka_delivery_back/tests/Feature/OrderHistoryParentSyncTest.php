<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Role;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryParentSyncTest extends TestCase
{
    use DatabaseTransactions;

    private int $clientId;
    private int $shopId;
    private int $collectorId;

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
            'title' => 'Parent sync shop',
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

        $this->collectorId = \DB::table('users')->insertGetId([
            'name' => 'Сборщиков',
            'surname' => 'Синхронный',
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // finishOrder() ходит в notifyCouriersOrderReady() -> User::role(Role::COURIER),
        // а в dev-БД роль 'courier' не засеяна — без неё Spatie бросает RoleDoesNotExist
        // ещё до наших assert'ов. Роль не привязана ни к одному User, поэтому
        // notifyCouriersOrderReady() уходит в ранний return (пустой $userIds).
        Role::findOrCreate(Role::COURIER, 'api');
    }

    private function insertOrder(array $attributes = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId,
            'uuid' => 'ohps-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_CREATED,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public function test_finish_order_syncs_parent_to_assembled_and_logs_history(): void
    {
        $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
        $this->insertOrder([
            'parent_order_id' => $parentId,
            'sub_number' => 1,
            'uuid' => 'ohps-' . uniqid() . '-1',
            'status_id' => Status::ORDER_ASSEMBLED,
        ]);
        $childBId = $this->insertOrder([
            'parent_order_id' => $parentId,
            'sub_number' => 2,
            'uuid' => 'ohps-' . uniqid() . '-2',
            'status_id' => Status::ORDER_PREPARING,
        ]);

        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $childBId,
            'user_id' => $this->collectorId,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AssemblyFinish::finishOrder([], Assembly::findOrFail($assemblyId));

        $row = OrderHistory::where('order_id', $parentId)->where('action', OrderHistory::ACTION_STATUS_CHANGED)->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_SYSTEM, $row->actor_type);
        $this->assertSame('Заказ на сборке', $row->old_value);
        $this->assertSame('Собрано', $row->new_value);
        $this->assertSame('Все подзаказы собраны', $row->comment);
        $this->assertSame(Status::ORDER_ASSEMBLED, (int) \DB::table('orders')->where('id', $parentId)->value('status_id'));
    }

    public function test_sync_parent_status_cancels_parent_when_all_children_cancelled_and_logs_history(): void
    {
        $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
        $this->insertOrder([
            'parent_order_id' => $parentId,
            'sub_number' => 1,
            'uuid' => 'ohps-' . uniqid() . '-1',
            'status_id' => Status::ORDER_CANCELLED,
        ]);
        $childBId = $this->insertOrder([
            'parent_order_id' => $parentId,
            'sub_number' => 2,
            'uuid' => 'ohps-' . uniqid() . '-2',
            'status_id' => Status::ORDER_CANCELLED,
        ]);

        AssemblyFinish::syncParentStatus(Order::findOrFail($childBId));

        $row = OrderHistory::where('order_id', $parentId)->where('action', OrderHistory::ACTION_CANCELED)->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_SYSTEM, $row->actor_type);
        $this->assertSame('Заказ на сборке', $row->old_value);
        $this->assertSame('Отменён', $row->new_value);
        $this->assertSame('Все подзаказы отменены', $row->comment);
        $this->assertSame(Status::ORDER_CANCELLED, (int) \DB::table('orders')->where('id', $parentId)->value('status_id'));
    }
}
