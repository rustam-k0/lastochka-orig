<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Модуль courier_assignment: самостоятельное взятие заказа курьером отключено,
 * заказы распределяет сборщик (Platrum 12183 / MID-151). Guard только в
 * __invoke: static takeOrder() продолжает работать для REST-назначения из
 * админки и DeliveryAssignCouriers.
 */
class DeliveryTakeOrderCourierAssignmentTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Take shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
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
        $this->courier = User::findOrFail($courierId);
        $this->courier->assignRole(Role::findOrCreate('courier', 'api'));
        \DB::table('shifts')->insert([
            'user_id' => $courierId, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertOrder(): int
    {
        return \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'dtoc-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private const MUTATION = '
        mutation Take($order_id: Int!) {
            DeliveryTakeOrder(order_id: $order_id) { code message }
        }
    ';

    /** @test */
    public function courier_cannot_self_take_order_when_module_enabled()
    {
        $this->setModule(true);
        $orderId = $this->insertOrder();

        $this->actingAs($this->courier, 'api');
        $response = $this->graphQL(self::MUTATION, ['order_id' => $orderId]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertStringContainsString(
            'Заказы назначает сборщик',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function courier_can_self_take_order_when_module_disabled()
    {
        $this->setModule(false);
        $orderId = $this->insertOrder();

        $this->actingAs($this->courier, 'api');
        $response = $this->graphQL(self::MUTATION, ['order_id' => $orderId]);

        $response->assertJson(['data' => ['DeliveryTakeOrder' => ['code' => 200]]]);
        $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }
}
