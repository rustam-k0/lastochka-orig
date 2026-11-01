<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Store;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductEventHandlerAdultOnlyTest extends TestCase
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

    public function test_product_created_event_stores_adult_only_true(): void
    {
        $store = Store::factory()->create(['code' => 'AO_001']);

        $eventData = $this->buildEventData([
            'shop_code' => 'AO_001',
            'sku' => 'WINE-1',
            'title' => 'Вино красное сухое',
            'adult_only' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'WINE-1',
            'adult_only' => true,
        ]);
    }

    public function test_product_created_event_defaults_adult_only_false(): void
    {
        $store = Store::factory()->create(['code' => 'AO_002']);

        $eventData = $this->buildEventData([
            'shop_code' => 'AO_002',
            'sku' => 'MILK-1',
            'title' => 'Молоко',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'MILK-1',
            'adult_only' => false,
        ]);
    }

    public function test_partial_update_without_key_keeps_adult_only(): void
    {
        $store = Store::factory()->create(['code' => 'AO_003']);
        $externalId = 'prod_'.Str::random(5);

        $created = $this->consumer->processEvent($this->buildEventData([
            'id' => $externalId,
            'shop_code' => 'AO_003',
            'sku' => 'WINE-2',
            'title' => 'Коньяк',
            'adult_only' => true,
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]));
        $this->assertEquals('ack', $created);

        $updated = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'updated',
            'id' => $externalId,
            'shop_code' => 'AO_003',
            'sku' => 'WINE-2',
            'title' => 'Коньяк выдержанный',
            'event_timestamp' => now()->toIso8601String(),
        ]));
        $this->assertEquals('ack', $updated);

        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'title' => 'Коньяк выдержанный',
            'adult_only' => true,
        ]);
    }
}
