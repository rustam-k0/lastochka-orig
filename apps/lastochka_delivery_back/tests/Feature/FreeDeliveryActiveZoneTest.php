<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * T7: порог бесплатной доставки (free_delivery_from) учитывает только АКТИВНЫЕ зоны.
 * Неактивная зона (is_active=0) не должна обнулять delivery_price.
 * Проверяется через AssemblyFinish::recalcOrderTotals — общий путь пересчёта,
 * используемый в т.ч. settlement'ом возвратов курьера в DeliveryFinish.
 */
class FreeDeliveryActiveZoneTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Free-delivery shop', 'id_1c' => random_int(900000, 999999), 'default' => 0,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertOrder(): Order
    {
        $id = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'fdz-' . uniqid(),
            'discount' => 0, 'discount_percent' => 0, 'bonus_spent' => 0,
            'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => 3, 'amount' => 900, 'delivery_price' => 300, 'is_heavy' => 0,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::find($id);
    }

    private function insertZone(bool $isActive): void
    {
        \DB::table('shop_zones')->insert([
            'shop_id' => $this->shopId, 'name' => $isActive ? 'active' : 'inactive',
            'delivery_price' => 300, 'min_order_price' => 0,
            'free_delivery_from' => 500, 'delivery_type' => 'own',
            'is_active' => $isActive ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test */
    public function inactive_zone_is_ignored_in_free_delivery_lookup(): void
    {
        $this->insertZone(isActive: false);
        $order = $this->insertOrder();

        // Сумма товаров 600 >= free_delivery_from 500, НО зона неактивна →
        // порог не применяется, доставка остаётся платной.
        $totals = AssemblyFinish::recalcOrderTotals($order, 600.0);

        $this->assertSame(300, (int) $totals['delivery_price'], 'неактивная зона не должна обнулять доставку');
    }

    /** @test */
    public function active_zone_applies_free_delivery(): void
    {
        $this->insertZone(isActive: true);
        $order = $this->insertOrder();

        // Контроль: активная зона с тем же порогом обнуляет доставку.
        $totals = AssemblyFinish::recalcOrderTotals($order, 600.0);

        $this->assertSame(0, (int) $totals['delivery_price'], 'активная зона должна обнулять доставку при достижении порога');
    }
}
