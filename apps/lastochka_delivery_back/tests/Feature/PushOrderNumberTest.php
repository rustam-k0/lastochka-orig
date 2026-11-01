<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Номер заказа в тексте push-уведомлений сотрудникам.
 *
 * В теле пуша должен быть сквозной номер заказа (Order::label = uuid витрины) —
 * тот же, что мобилка рисует в шапке экрана заказа. Внутренний Order::id
 * остаётся только в data-payload (по нему мобилка роутит на экран).
 */
class PushOrderNumberTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Push number shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Courier', 'surname' => 'Тестовый ' . uniqid(),
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        User::findOrFail($courierId)->assignRole(Role::findOrCreate('courier', 'api'));
        \DB::table('shifts')->insert([
            'user_id' => $courierId, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => false]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function makeAssembledOrder(string $uuid): Order
    {
        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => $uuid,
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::findOrFail($orderId);
    }

    /** @test */
    public function ready_for_delivery_push_uses_storefront_number_in_body()
    {
        $order = $this->makeAssembledOrder('pon-' . uniqid());

        $captured = [];
        $this->mock(FcmNotificationService::class)
            ->shouldReceive('sendToUsers')
            ->once()
            ->andReturnUsing(function ($userIds, $title, $body, $data) use (&$captured) {
                $captured = compact('userIds', 'title', 'body', 'data');
            });

        $method = new \ReflectionMethod(AssemblyFinish::class, 'notifyCouriersOrderReady');
        $method->setAccessible(true);
        $method->invoke(null, $order);

        $this->assertSame("Номер: {$order->uuid}", $captured['body']);
        $this->assertSame((string) $order->id, $captured['data']['order_id']);
    }

    /** @test */
    public function label_by_id_returns_storefront_number()
    {
        $order = $this->makeAssembledOrder('pon-' . uniqid());

        $this->assertSame($order->uuid, Order::labelById($order->id));
    }

    /** @test */
    public function label_by_id_falls_back_to_internal_id_for_unknown_order()
    {
        $missingId = ((int) \DB::table('orders')->max('id')) + 1000;

        $this->assertSame((string) $missingId, Order::labelById($missingId));
    }
}
