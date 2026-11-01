<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Поля фичи «Тяжёлый заказ»/«Совместная доставка» в OrderResource —
 * standard-режим мобилки (GET /api/v1/orders/{order}, вне marketplace).
 * Симметрично ClientOrderResource, но для одиночного заказа standard-flow.
 */
final class OrderResourceHeavyFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_heavy_and_joint_delivery_fields(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'is_heavy' => true,
            'couriers_count' => 2,
        ]);
        // actual_weight в граммах: 3000 + 2500 = 5500 г → 5.5 кг.
        OrderItem::factory()->for($order)->create(['actual_weight' => 3000]);
        OrderItem::factory()->for($order)->create(['actual_weight' => 2500]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('data.isHeavy', true)
            ->assertJsonPath('data.couriersCount', 2)
            ->assertJsonPath('data.isJointDelivery', true)
            ->assertJsonPath('data.weightTotal', 5.5);
    }

    public function test_show_is_joint_delivery_false_with_single_courier(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'is_heavy' => false,
            'couriers_count' => 1,
        ]);
        // actual_weight 1500 г → 1.5 кг (дробное, чтобы JSON отдал float, не int).
        OrderItem::factory()->for($order)->create(['actual_weight' => 1500]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('data.isHeavy', false)
            ->assertJsonPath('data.couriersCount', 1)
            ->assertJsonPath('data.isJointDelivery', false)
            ->assertJsonPath('data.weightTotal', 1.5);
    }
}
