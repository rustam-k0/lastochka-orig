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
 * Модуль courier_assignment: общий пуш «заказ готов к доставке» всем курьерам
 * смены не отправляется — курьеры не видят общий список, назначенному курьеру
 * пуш order_assigned шлёт DeliveryAssignCouriers (Platrum 12183 / MID-151).
 */
class AssemblyFinishCourierAssignmentPushTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Push shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Курьер на смене магазина — чтобы у пуша был получатель
        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Courier', 'surname' => 'Тестовый ' . uniqid(),
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        User::findOrFail($courierId)->assignRole(Role::findOrCreate('courier', 'api'));
        \DB::table('shifts')->insert([
            'user_id' => $courierId, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAssembledOrder(): Order
    {
        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'afp-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::findOrFail($orderId);
    }

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function callNotify(Order $order): void
    {
        $method = new \ReflectionMethod(AssemblyFinish::class, 'notifyCouriersOrderReady');
        $method->setAccessible(true);
        $method->invoke(null, $order);
    }

    /** @test */
    public function broadcast_push_skipped_when_module_enabled()
    {
        $this->setModule(true);
        $this->mock(FcmNotificationService::class)
            ->shouldNotReceive('sendToUsers');

        $this->callNotify($this->makeAssembledOrder());

        // Мок без ожиданий падает в tearDown при вызове sendToUsers;
        // явный assert — чтобы тест не был risky.
        $this->assertTrue(true);
    }

    /** @test */
    public function broadcast_push_sent_when_module_disabled()
    {
        $this->setModule(false);
        $this->mock(FcmNotificationService::class)
            ->shouldReceive('sendToUsers')->once();

        $this->callNotify($this->makeAssembledOrder());
    }
}
