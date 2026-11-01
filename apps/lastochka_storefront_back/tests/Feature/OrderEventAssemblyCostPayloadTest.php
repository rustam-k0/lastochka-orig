<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * События заказа публикуют assembly_cost (стоимость сборки, модуль assembly_cost).
 *
 * Без отдельного поля delivery_back не может сохранить слагаемое в пересчётах
 * суммы после сборки: grand_total пересчитывался без стоимости сборки, и capture
 * в YooKassa списывал меньше захолдированного.
 */
final class OrderEventAssemblyCostPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();

        return Order::factory()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'assembly_cost' => 150,
        ]);
    }

    public function test_to_event_contains_assembly_cost(): void
    {
        $event = app(OrderEventTransformer::class)->toEvent($this->makeOrder()->fresh());

        $this->assertSame(150.0, $event['assembly_cost']);
    }

    public function test_order_created_custom_event_contains_assembly_cost(): void
    {
        $event = app(OrderEventTransformer::class)
            ->toCustomEvent($this->makeOrder()->fresh(), 'order_created', []);

        $this->assertSame(150.0, $event['assembly_cost']);
    }
}
