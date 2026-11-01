<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyCancel;
use App\Models\OrderHistory;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryAssemblyCancelTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
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
            'title' => 'Assembly cancel history shop',
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
            'surname' => 'Паузов',
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrder(array $attributes = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId,
            'uuid' => 'oac-' . uniqid(),
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

    private function insertAssembly(int $orderId): int
    {
        return \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $this->collectorId,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_assembly_cancel_logs_status_change_for_order(): void
    {
        $orderId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
        $assemblyId = $this->insertAssembly($orderId);

        AssemblyCancel::assemblyCancel($assemblyId);

        $row = OrderHistory::where('order_id', $orderId)->where('action', OrderHistory::ACTION_ASSEMBLY_PAUSED)->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_STAFF, $row->actor_type);
        $this->assertSame($this->collectorId, $row->actor_id);
        $this->assertStringContainsString('Паузов', $row->actor_name);
        $this->assertSame('Заказ на сборке', $row->old_value);
        $this->assertSame('Создан', $row->new_value);
    }

    public function test_assembly_cancel_logs_parent_rollback_when_no_active_siblings(): void
    {
        $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
        $childId = $this->insertOrder([
            'parent_order_id' => $parentId,
            'sub_number' => 1,
            'uuid' => 'oac-' . uniqid() . '-1',
            'status_id' => Status::ORDER_PREPARING,
        ]);
        $assemblyId = $this->insertAssembly($childId);

        AssemblyCancel::assemblyCancel($assemblyId);

        $row = OrderHistory::where('order_id', $parentId)->where('action', OrderHistory::ACTION_STATUS_CHANGED)->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_SYSTEM, $row->actor_type);
        $this->assertSame('Заказ на сборке', $row->old_value);
        $this->assertSame('Создан', $row->new_value);
    }

    public function test_assembly_cancel_does_not_roll_back_parent_when_sibling_still_active(): void
    {
        $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
        $childId = $this->insertOrder([
            'parent_order_id' => $parentId,
            'sub_number' => 1,
            'uuid' => 'oac-' . uniqid() . '-1',
            'status_id' => Status::ORDER_PREPARING,
        ]);
        $this->insertOrder([
            'parent_order_id' => $parentId,
            'sub_number' => 2,
            'uuid' => 'oac-' . uniqid() . '-2',
            'status_id' => Status::ORDER_PREPARING,
        ]);
        $assemblyId = $this->insertAssembly($childId);

        AssemblyCancel::assemblyCancel($assemblyId);

        $this->assertSame(
            0,
            OrderHistory::where('order_id', $parentId)->count()
        );
    }
}
