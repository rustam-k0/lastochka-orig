<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Store;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductEventHandlerPickupOnlyTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
    }

    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_'.Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'TEST_'.Str::random(3),
            'sku' => 'SKU-'.Str::random(5),
            'title' => 'Тестовый товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }

    public function test_product_created_event_stores_pickup_only_true(): void
    {
        $store = Store::factory()->create(['code' => 'PO_001']);

        $eventData = $this->buildEventData([
            'shop_code' => 'PO_001',
            'sku' => 'WINE-1',
            'title' => 'Вино красное сухое',
            'pickup_only' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'WINE-1',
            'pickup_only' => true,
        ]);
    }

    public function test_product_created_event_defaults_pickup_only_false(): void
    {
        $store = Store::factory()->create(['code' => 'PO_002']);

        $eventData = $this->buildEventData([
            'shop_code' => 'PO_002',
            'sku' => 'MILK-1',
            'title' => 'Молоко',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'MILK-1',
            'pickup_only' => false,
        ]);
    }
}
