<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Order\OrderCancelByCollector;
use App\Models\Assembly;
use App\Models\Order;
use App\Models\OrderCancelReason;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Models\User;
use GraphQL\Error\UserError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderCancelByCollectorTest extends TestCase
{
    use DatabaseTransactions;

    private int $clientId;
    private int $shopId;
    private int $collectorId;
    private int $otherCollectorId;

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
            'title' => 'Collector cancel shop',
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
            'surname' => 'Тестовый',
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->otherCollectorId = \DB::table('users')->insertGetId([
            'name' => 'Чужой',
            'surname' => 'Сборщик',
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrder(int $statusId): int
    {
        return \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'occt-' . uniqid(),
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

    public function test_order_cancel_by_collector_logs_canceled_with_reason_from_dictionary(): void
    {
        $orderId = $this->insertOrder(Status::ORDER_PREPARING);
        $assembly = Assembly::create(['order_id' => $orderId, 'user_id' => $this->collectorId]);
        $reason = OrderCancelReason::create(['title' => 'Клиент недоступен', 'is_active' => true, 'position' => 1]);

        $this->actingAs(User::find($this->collectorId), 'api');

        $result = (new OrderCancelByCollector())->__invoke(null, [
            'assembly_id' => $assembly->id,
            'reason_id' => $reason->id,
            'custom_reason' => null,
        ]);

        $this->assertSame(200, $result['code']);

        $order = Order::find($orderId);
        $this->assertSame(Status::ORDER_CANCELED, $order->status_id);
        $this->assertSame('Клиент недоступен', $order->operator_comment);

        $row = OrderHistory::where('order_id', $orderId)->where('action', OrderHistory::ACTION_CANCELED)->first();
        $this->assertNotNull($row);
        $this->assertSame('Клиент недоступен', $row->comment);
        $this->assertSame($this->collectorId, $row->actor_id);

        $this->assertNotNull(Assembly::withoutGlobalScopes()->find($assembly->id)->finished_at);
    }

    public function test_order_cancel_by_collector_accepts_custom_reason(): void
    {
        $orderId = $this->insertOrder(Status::ORDER_PREPARING);
        $assembly = Assembly::create(['order_id' => $orderId, 'user_id' => $this->collectorId]);

        $this->actingAs(User::find($this->collectorId), 'api');

        (new OrderCancelByCollector())->__invoke(null, [
            'assembly_id' => $assembly->id,
            'reason_id' => null,
            'custom_reason' => '  Магазин закрывается раньше  ',
        ]);

        $order = Order::find($orderId);
        $this->assertSame('Магазин закрывается раньше', $order->operator_comment);
    }

    public function test_order_cancel_by_collector_rejects_foreign_assembly(): void
    {
        $orderId = $this->insertOrder(Status::ORDER_PREPARING);
        $assembly = Assembly::create(['order_id' => $orderId, 'user_id' => $this->otherCollectorId]);

        $this->actingAs(User::find($this->collectorId), 'api');

        $this->expectException(ModelNotFoundException::class);

        (new OrderCancelByCollector())->__invoke(null, [
            'assembly_id' => $assembly->id,
            'reason_id' => null,
            'custom_reason' => 'Не моя сборка',
        ]);
    }

    public function test_order_cancel_by_collector_rejects_order_not_in_preparing_status(): void
    {
        $orderId = $this->insertOrder(Status::ORDER_ASSEMBLED);
        $assembly = Assembly::create(['order_id' => $orderId, 'user_id' => $this->collectorId]);

        $this->actingAs(User::find($this->collectorId), 'api');

        $this->expectException(UserError::class);

        (new OrderCancelByCollector())->__invoke(null, [
            'assembly_id' => $assembly->id,
            'reason_id' => null,
            'custom_reason' => 'Заказ уже собран',
        ]);
    }
}
