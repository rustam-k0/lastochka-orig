<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderEventHeavyPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_event_contains_heavy_fields(): void
    {
        // Фикстура: store + order(is_heavy=true) + 2 позиции: штучный с весом 5000 г × 2
        // и штучный БЕЗ веса (weight=null) → total_weight=10000, is_weight_partial=true.
        $store = Store::factory()->create();
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'is_heavy' => true,
        ]);

        $withWeight = Product::factory()->for($store)->create(['weight' => 5000, 'quantity_step' => 1]);
        $noWeight = Product::factory()->for($store)->create(['weight' => null, 'quantity_step' => 1]);

        OrderItem::factory()->for($order)->for($withWeight)->create(['quantity' => 2]);
        OrderItem::factory()->for($order)->for($noWeight)->create(['quantity' => 1]);

        $event = app(OrderEventTransformer::class)->toEvent($order->fresh());

        $this->assertTrue($event['is_heavy']);
        $this->assertSame(10000, $event['total_weight']);
        $this->assertTrue($event['is_weight_partial']);
    }
}
