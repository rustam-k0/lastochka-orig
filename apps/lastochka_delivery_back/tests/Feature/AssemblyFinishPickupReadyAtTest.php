<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\Role;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Информация по самовывозу в карточке заказа (Platrum MEN-IN-DEV-11279):
 * pickup_ready_at должен проставляться в момент перехода заказа в
 * ORDER_ASSEMBLED (это и есть «готовность к выдаче»), один раз (не
 * перезаписывается при повторных вызовах finishOrder).
 */
class AssemblyFinishPickupReadyAtTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
    private int $collectorId;

    protected function setUp(): void
    {
        parent::setUp();

        // finishOrder() при успехе шлёт пуш всем курьерам магазина (notifyCouriersOrderReady) —
        // роль должна существовать, иначе Spatie::role() падает "no role named `courier`"
        // даже когда курьеров с этой ролью нет.
        Role::findOrCreate('courier', 'api');

        $this->clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Pickup ready shop',
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 1,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 1,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->collectorId = \DB::table('users')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrder(): int
    {
        return \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'pru-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 1,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_PREPARING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /** @test */
    public function it_sets_pickup_ready_at_when_order_becomes_assembled(): void
    {
        $orderId = $this->insertOrder();
        $assemblyId = $this->insertAssembly($orderId);

        $this->assertNull(\DB::table('orders')->where('id', $orderId)->value('pickup_ready_at'));

        $assembly = Assembly::with('order')->findOrFail($assemblyId);
        AssemblyFinish::finishOrder([], $assembly);

        $order = \DB::table('orders')->where('id', $orderId)->first();
        $this->assertSame(Status::ORDER_ASSEMBLED, (int) $order->status_id);
        $this->assertNotNull($order->pickup_ready_at);
    }

    /** @test */
    public function it_does_not_overwrite_pickup_ready_at_on_repeated_call(): void
    {
        $orderId = $this->insertOrder();
        $assemblyId = $this->insertAssembly($orderId);

        $assembly = Assembly::with('order')->findOrFail($assemblyId);
        AssemblyFinish::finishOrder([], $assembly);

        $firstReadyAt = \DB::table('orders')->where('id', $orderId)->value('pickup_ready_at');
        $this->assertNotNull($firstReadyAt);

        // Симулируем повторный вызов (например ретрай) — значение не должно измениться.
        sleep(1);
        $assembly->refresh();
        $assembly->order->refresh();
        AssemblyFinish::finishOrder([], $assembly);

        $secondReadyAt = \DB::table('orders')->where('id', $orderId)->value('pickup_ready_at');
        $this->assertEquals($firstReadyAt, $secondReadyAt);
    }
}
