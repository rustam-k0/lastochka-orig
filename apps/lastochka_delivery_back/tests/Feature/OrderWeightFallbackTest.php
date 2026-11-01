<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Support\OrderWeight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Вес заказа в выдачах приложения сборщика (MID-191).
 *
 * Колонка orders.total_weight приходит из витрины при оформлении и
 * перезаписывается фактическим весом при завершении сборки. Для заказов мимо
 * витрины (1С, админка) её нет — вес резолвится на лету по составу.
 */
class OrderWeightFallbackTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $collector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Weight shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0, 'heavy_order_threshold' => 15000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $userId = \DB::table('users')->insertGetId([
            'name' => 'Сборщик', 'surname' => 'Весовой ' . uniqid(), 'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->collector = User::findOrFail($userId);
        $this->collector->assignRole(Role::findOrCreate('collector', 'api'));
        \DB::table('shifts')->insert([
            'user_id' => $this->collector->id, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enableHeavyOrders(bool $enabled = true): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => $enabled]]),
                'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function insertOrder(array $attrs = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'weight-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_CREATED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** @param array{weight?: int|null, info_weight?: int|null, info_weight_type_id?: int|null} $attrs */
    private function insertProduct(array $attrs = []): int
    {
        return \DB::table('products')->insertGetId(array_merge([
            'shop_id' => $this->shopId, 'title' => 'Товар ' . uniqid(),
            'articul' => (string) random_int(900000, 999999),
            'unit_type_id' => 2, 'status_id' => Status::CODE_ACTIVE,
            'weight' => null, 'info_weight' => null, 'info_weight_type_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function insertItem(int $orderId, int $productId, int $quantity = 1, array $attrs = []): void
    {
        \DB::table('order_items')->insert(array_merge([
            'order_id' => $orderId, 'product_id' => $productId, 'quantity' => $quantity,
            'price' => 100, 'status_id' => Status::ORDER_ITEM_WAITING,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** @test */
    public function stored_weight_wins_over_calculation()
    {
        $orderId = $this->insertOrder(['total_weight' => 3000, 'is_weight_partial' => 1]);
        $this->insertItem($orderId, $this->insertProduct(['weight' => 500]), 2);

        $order = Order::findOrFail($orderId);

        // Зафиксированное значение (витрина/сборка) не пересчитывается
        $this->assertSame(3000, OrderWeight::totalWeight($order));
        $this->assertTrue(OrderWeight::isWeightPartial($order));
    }

    /** @test */
    public function weight_is_calculated_for_order_without_stored_value()
    {
        $orderId = $this->insertOrder();
        $this->insertItem($orderId, $this->insertProduct(['weight' => 500]), 2);           // 1000 г
        $this->insertItem($orderId, $this->insertProduct([                                  // 2 порции × 1.5 кг
            'info_weight' => 1500, 'info_weight_type_id' => 2,
        ]), 2);

        $order = Order::findOrFail($orderId);

        $this->assertSame(4000, OrderWeight::totalWeight($order));
        $this->assertFalse(OrderWeight::isWeightPartial($order));
    }

    /** @test */
    public function item_without_weight_marks_result_partial()
    {
        $orderId = $this->insertOrder();
        $this->insertItem($orderId, $this->insertProduct(['weight' => 700]), 1);
        $this->insertItem($orderId, $this->insertProduct(['weight' => null]), 1);

        $order = Order::findOrFail($orderId);

        $this->assertSame(700, OrderWeight::totalWeight($order));
        $this->assertTrue(OrderWeight::isWeightPartial($order));
    }

    /** @test */
    public function weight_is_null_when_nothing_can_be_counted()
    {
        $orderId = $this->insertOrder();
        $this->insertItem($orderId, $this->insertProduct(['weight' => null]), 1);

        $order = Order::findOrFail($orderId);

        // null, а не 0 — клиент скрывает строку веса
        $this->assertNull(OrderWeight::totalWeight($order));
        $this->assertTrue(OrderWeight::isWeightPartial($order));
    }

    /** @test */
    public function cancelled_items_are_excluded()
    {
        $orderId = $this->insertOrder();
        $this->insertItem($orderId, $this->insertProduct(['weight' => 800]), 1);
        $this->insertItem($orderId, $this->insertProduct(['weight' => 900]), 1, [
            'status_id' => Status::ORDER_ITEM_CANCELED,
        ]);

        $this->assertSame(800, OrderWeight::totalWeight(Order::findOrFail($orderId)));
    }

    /** @test */
    public function heavy_flag_derives_from_calculated_weight()
    {
        $this->enableHeavyOrders();
        $orderId = $this->insertOrder();                                    // порог магазина 15 кг
        $this->insertItem($orderId, $this->insertProduct(['weight' => 8000]), 2);   // 16 кг

        $this->assertTrue(OrderWeight::isHeavy(Order::findOrFail($orderId)));
    }

    /** @test */
    public function heavy_flag_is_not_derived_when_module_disabled()
    {
        $this->enableHeavyOrders(false);
        $orderId = $this->insertOrder();
        $this->insertItem($orderId, $this->insertProduct(['weight' => 8000]), 2);

        $this->assertFalse(OrderWeight::isHeavy(Order::findOrFail($orderId)));
    }

    /** @test */
    public function stored_heavy_flag_is_kept_as_is()
    {
        $this->enableHeavyOrders(false);
        $orderId = $this->insertOrder(['is_heavy' => 1, 'total_weight' => 20000]);

        // Признак заморожен сборкой/витриной — отдаём как есть даже при выключенном модуле
        $this->assertTrue(OrderWeight::isHeavy(Order::findOrFail($orderId)));
    }

    /** @test */
    public function order_list_returns_weight_with_unit_before_assembly()
    {
        $this->enableHeavyOrders();
        // Вкладка «Новые»: заказ ещё не в сборке, витрина вес не присылала
        $orderId = $this->insertOrder();
        $this->insertItem($orderId, $this->insertProduct(['weight' => 8000]), 2);

        $this->actingAs($this->collector, 'api');
        $response = $this->graphQL('{
            orders_paginate(status: 1, first: 100) {
                data { id total_weight total_weight_unit is_weight_partial is_heavy }
            }
        }');

        $order = collect($response->json('data.orders_paginate.data'))
            ->firstWhere('id', $orderId);

        $this->assertNotNull($order, 'Заказ не попал в выдачу сборщика');
        $this->assertSame(16000, $order['total_weight']);
        $this->assertSame('g', $order['total_weight_unit']);
        $this->assertFalse($order['is_weight_partial']);
        $this->assertTrue($order['is_heavy']);
    }
}
