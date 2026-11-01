<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderEventTransformerObjectDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_object_data_null_for_normal_address(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create();
        OrderAddress::factory()->for($order)->create(['object_data' => null]);

        $order->load(['user', 'store', 'items.supplements.supplement', 'items.supplements.values', 'address', 'promocode']);
        $payload = app(OrderEventTransformer::class)->toEvent($order);

        $this->assertArrayHasKey('object_data', $payload['address']);
        $this->assertNull($payload['address']['object_data']);
    }

    public function test_payload_includes_object_data_for_object_address(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create();
        OrderAddress::factory()->for($order)->create([
            'object_data' => [
                'name' => 'Многоэтажка',
                'contact_name' => 'Иванов',
                'has_lift' => true,
                'photos' => [
                    ['url' => 'https://example/a.jpg', 'mime' => 'image/jpeg'],
                ],
            ],
        ]);

        $order->load(['user', 'store', 'items.supplements.supplement', 'items.supplements.values', 'address', 'promocode']);
        $payload = app(OrderEventTransformer::class)->toEvent($order);

        $obj = $payload['address']['object_data'];
        $this->assertSame('Многоэтажка', $obj['name']);
        $this->assertSame('Иванов', $obj['contact_name']);
        $this->assertTrue($obj['has_lift']);
        $this->assertCount(1, $obj['photos']);
    }
}
