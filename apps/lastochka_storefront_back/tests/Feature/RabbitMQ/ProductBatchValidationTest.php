<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\RabbitMQ\Transformers\ProductBatchEventTransformer;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Батч-события 1С (контракт 2026-06-24-1c-rabbitmq-direct-contract.md, раздел 6).
 * Трансформер validate-only: EventConsumer вызывает validate() ДО роутинга
 * на handler, поэтому requiredFields матчат конверт батча, а не товар.
 */
final class ProductBatchValidationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function envelope(string $eventType = 'price_batch_updated'): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => $eventType,
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => 'BT_001',
            'items' => [['id' => 'ext-1', 'sku' => 'S-1', 'price' => 10.0]],
        ];
    }

    public function test_validates_all_three_batch_event_types(): void
    {
        $transformer = new ProductBatchEventTransformer();

        $this->assertTrue($transformer->validate($this->envelope('price_batch_updated')));
        $this->assertTrue($transformer->validate($this->envelope('stock_batch_updated')));
        $this->assertTrue($transformer->validate($this->envelope('batch_updated')));
    }

    public function test_rejects_envelope_without_required_fields(): void
    {
        $transformer = new ProductBatchEventTransformer();

        foreach (['event_id', 'shop_code', 'source', 'items'] as $field) {
            $this->assertFalse(
                $transformer->validate(Arr::except($this->envelope(), [$field])),
                "Конверт без {$field} должен отклоняться"
            );
        }
    }

    public function test_entity_config_registered(): void
    {
        $config = config('rabbitmq-events.entities.product_batches');

        $this->assertSame(ProductBatchEventTransformer::class, $config['transformer']);
        $this->assertSame(ProductBatchHandler::class, $config['handler']);
        $this->assertSame([
            'product.price_batch_updated',
            'product.stock_batch_updated',
            'product.batch_updated',
        ], $config['routing_key_patterns']);
        $this->assertSame(
            'storefront_product_batches',
            config('rabbitmq-events.inbound_queues.product_batches')
        );
    }
}
