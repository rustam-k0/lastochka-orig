<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Exchange1C\RestsImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    $this->dispatched = [];
    $dispatchedRef = &$this->dispatched;

    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) use (&$dispatchedRef) {
            $dispatchedRef[] = compact('entityType', 'model', 'eventType', 'payload');

            return 'test-event-id';
        });
});

afterEach(function () {
    Mockery::close();
});

/**
 * Тестовая обёртка: даёт доступ к protected helper, который реализует агрегацию.
 */
function makeImporter(): object
{
    return new class extends RestsImporter {
        public function call(Product $product, float $newTotal, float $oldTotal): void
        {
            $this->dispatchAggregatedStockUpdated($product, $newTotal, $oldTotal);
        }
    };
}

test('dispatchAggregatedStockUpdated publishes one event with aggregated quantity', function () {
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-xyz';
    $product->sku = 'SKU-001';

    makeImporter()->call($product, 9.0, 0.0);

    expect($this->dispatched)->toHaveCount(1)
        ->and($this->dispatched[0]['entityType'])->toBe('products')
        ->and($this->dispatched[0]['eventType'])->toBe('stock_updated')
        ->and((float) $this->dispatched[0]['payload']['quantity'])->toBe(9.0)
        ->and((float) $this->dispatched[0]['payload']['old_quantity'])->toBe(0.0);
});

test('dispatchAggregatedStockUpdated publishes no event when total quantity unchanged', function () {
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-unchanged';

    makeImporter()->call($product, 8.0, 8.0);

    expect($this->dispatched)->toBeEmpty();
});

test('dispatchAggregatedStockUpdated payload is product-level (no warehouse_id)', function () {
    // Platrum 9853 regression: payload per-product, а не per-warehouse.
    // Storefront должен получать итоговый остаток, а не одного склада.
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-regression';

    makeImporter()->call($product, 9.0, 0.0);

    expect($this->dispatched[0]['payload'])
        ->not->toHaveKey('warehouse_id')
        ->not->toHaveKey('warehouse_name');
});

test('dispatchAggregatedStockUpdated handles errors gracefully (does not bubble up)', function () {
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-fail';

    Mockery::getContainer()->getMocks()[CustomEventPublisher::class]
        ->shouldReceive('dispatch')
        ->andThrow(new \Exception('RabbitMQ unavailable'));

    // Не должен пробросить исключение — импорт продолжается даже при проблемах RabbitMQ
    makeImporter()->call($product, 5.0, 3.0);

    expect(true)->toBeTrue();
});
