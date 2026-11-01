<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * product.stock_batch_updated: guard stock_mode (ignore/fixed = no-op),
 * пересчёт через stockUnitMultiplier идентичен одиночному событию.
 * warehouse_id/warehouse_name в конверте storefront не использует.
 */
final class ProductBatchStockTest extends TestCase
{
    use RefreshDatabase;

    private function seedProduct(string $externalId, array $overrides = []): Product
    {
        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
        $consumer->processEvent(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'created',
            'event_timestamp' => now()->subHour()->toIso8601String(),
            'id' => $externalId,
            'source' => '1c',
            'shop_code' => 'BT_001',
            'sku' => 'SKU-' . $externalId,
            'title' => 'Товар ' . $externalId,
            'price' => 100.00,
            'quantity' => 5,
            'active' => true,
        ], $overrides));

        return Product::where('sku', 'SKU-' . $externalId)->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function handle(array $overrides = []): string
    {
        return app(ProductBatchHandler::class)->handle(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => 'stock_batch_updated',
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => 'BT_001',
            'warehouse_id' => 'wh-1',
            'warehouse_name' => 'Склад 1',
            'items' => [],
        ], $overrides));
    }

    public function test_updates_quantities(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $a = $this->seedProduct('ext-a');
        $b = $this->seedProduct('ext-b');

        $result = $this->handle([
            'items' => [
                ['id' => 'ext-a', 'sku' => 'SKU-ext-a', 'quantity' => 12],
                ['id' => 'ext-b', 'sku' => 'SKU-ext-b', 'quantity' => 0],
            ],
        ]);

        $this->assertSame('ack', $result);
        $this->assertSame(12, (int) $a->fresh()->quantity);
        $this->assertSame(0, (int) $b->fresh()->quantity);
    }

    public function test_ignore_and_fixed_stock_modes_are_untouched(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $ignored = $this->seedProduct('ext-i');
        $fixed = $this->seedProduct('ext-f');

        // stock_mode управляется сайтом — правим напрямую, минуя события
        Product::withoutEvents(function () use ($ignored, $fixed): void {
            $ignored->forceFill(['stock_mode' => 'ignore', 'quantity' => 7])->save();
            $fixed->forceFill(['stock_mode' => 'fixed', 'quantity' => 3])->save();
        });

        $this->handle([
            'items' => [
                ['id' => 'ext-i', 'sku' => 'SKU-ext-i', 'quantity' => 99],
                ['id' => 'ext-f', 'sku' => 'SKU-ext-f', 'quantity' => 99],
            ],
        ]);

        $this->assertSame(7, (int) $ignored->fresh()->quantity);
        $this->assertSame(3, (int) $fixed->fresh()->quantity);
    }

    /**
     * Пересчёт единиц идентичен одиночному событию: сравниваем результат
     * батча с тем, что даёт processStockUpdatedEvent на товаре-близнеце
     * (без хардкода множителя кг → граммы).
     */
    public function test_unit_multiplier_matches_single_event_behavior(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $batchProduct = $this->seedProduct('ext-kg', ['unit_type' => 'kg']);
        $twin = $this->seedProduct('ext-kg-twin', ['unit_type' => 'kg']);

        $this->handle([
            'items' => [['id' => 'ext-kg', 'sku' => 'SKU-ext-kg', 'quantity' => 2]],
        ]);

        app(ProductEventTransformer::class)->processStockUpdatedEvent($twin->fresh(), [
            'quantity' => 2,
            'shop_code' => 'BT_001',
        ]);

        $this->assertSame(
            (int) $twin->fresh()->quantity,
            (int) $batchProduct->fresh()->quantity
        );
    }

    public function test_fractional_kg_in_batch_is_not_truncated(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-kg-frac', ['unit_type' => 'kg']);

        $this->handle([
            'items' => [['id' => 'ext-kg-frac', 'sku' => 'SKU-ext-kg-frac', 'quantity' => 0.216]],
        ]);

        $this->assertSame(216, (int) $product->fresh()->quantity);
    }

    public function test_missing_quantity_is_error_but_batch_survives(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $a = $this->seedProduct('ext-a');
        $b = $this->seedProduct('ext-b');

        $result = $this->handle([
            'items' => [
                ['id' => 'ext-a', 'sku' => 'SKU-ext-a'], // нет quantity
                ['id' => 'ext-b', 'sku' => 'SKU-ext-b', 'quantity' => 42],
            ],
        ]);

        $this->assertSame('ack', $result);
        $this->assertSame(5, (int) $a->fresh()->quantity);
        $this->assertSame(42, (int) $b->fresh()->quantity);
    }
}
