<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductEventHandlerAdditionalInfoTest extends TestCase
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

    public function test_product_created_event_stores_additional_info(): void
    {
        $store = Store::factory()->create(['code' => 'AI_001']);

        $result = $this->consumer->processEvent($this->buildEventData([
            'shop_code' => 'AI_001',
            'sku' => 'DIF-1',
            'additional_info' => 'Гарантия производителя 12 месяцев',
        ]));

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'DIF-1',
            'additional_info' => 'Гарантия производителя 12 месяцев',
        ]);
    }

    public function test_product_created_event_without_key_leaves_null(): void
    {
        $store = Store::factory()->create(['code' => 'AI_002']);

        $result = $this->consumer->processEvent($this->buildEventData([
            'shop_code' => 'AI_002',
            'sku' => 'DIF-2',
        ]));

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'DIF-2',
            'additional_info' => null,
        ]);
    }

    public function test_partial_update_event_does_not_overwrite_additional_info(): void
    {
        $store = Store::factory()->create(['code' => 'AI_003']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'AI-KEEP',
            'title' => 'Сохраняем текст',
            'price' => 100,
            'is_active' => true,
            'additional_info' => 'Гарантия производителя 12 месяцев',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'ai_keep',
            'source' => 'bagisto',
            'shop_code' => 'AI_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Партиальное событие (1С/retailbridge) — БЕЗ ключа additional_info. Не должно затирать текст.
        $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'updated',
            'id' => 'ai_keep',
            'shop_code' => 'AI_003',
            'sku' => 'AI-KEEP',
            'title' => 'Обновлённое имя',
            'price' => 150.00,
        ]));

        $product->refresh();
        $this->assertSame(
            'Гарантия производителя 12 месяцев',
            $product->additional_info,
            'partial update без ключа не должен затирать additional_info'
        );
        $this->assertSame('Обновлённое имя', $product->title);
    }

    public function test_update_event_can_clear_additional_info_explicitly(): void
    {
        $store = Store::factory()->create(['code' => 'AI_004']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'AI-CLEAR',
            'title' => 'Очищаем текст',
            'price' => 100,
            'is_active' => true,
            'additional_info' => 'Гарантия производителя 12 месяцев',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'ai_clear',
            'source' => 'bagisto',
            'shop_code' => 'AI_004',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Явный null из admin_back — очищает поле.
        $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'updated',
            'id' => 'ai_clear',
            'shop_code' => 'AI_004',
            'sku' => 'AI-CLEAR',
            'title' => 'Очищаем текст',
            'price' => 100.00,
            'additional_info' => null,
        ]));

        $product->refresh();
        $this->assertNull($product->additional_info, 'явный null в событии должен очищать additional_info');
    }
}
