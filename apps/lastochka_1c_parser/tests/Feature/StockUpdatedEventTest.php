<?php

use App\Models\Product;
use App\Services\Exchange1C\OffersImporter;
use App\Services\Exchange1C\RestsImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
});

/**
 * Регрессионный тест корневого бага: OffersImporter писал остаток в БД, но НЕ публиковал
 * stock_updated (полагался на product.updated/touch, отключённый в config). Остатки из
 * offers.xml молча терялись и не доходили до курьерки. Трейт PublishesAggregatedStock
 * закрывает оба пути импорта (offers + rests) единой явной публикацией.
 */

test('OffersImporter публикует stock_updated при изменении агрегированного остатка', function () {
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-001';

    $dispatched = [];
    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) use (&$dispatched) {
            $dispatched[] = compact('entityType', 'eventType', 'payload');
            return 'test-event-id';
        });

    $importer = new class extends OffersImporter {
        public function callDispatch(Product $product, float $newTotal, float $oldTotal): void
        {
            $this->dispatchAggregatedStockUpdated($product, $newTotal, $oldTotal);
        }
    };

    $importer->callDispatch($product, 150.0, 0.0);

    expect($dispatched)->toHaveCount(1);
    expect($dispatched[0]['entityType'])->toBe('products');
    expect($dispatched[0]['eventType'])->toBe('stock_updated');
    expect($dispatched[0]['payload']['quantity'])->toBe(150.0);
    expect($dispatched[0]['payload']['old_quantity'])->toBe(0.0);
});

test('stock_updated НЕ публикуется когда агрегированный остаток не изменился', function () {
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-001';

    $dispatched = [];
    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function (...$args) use (&$dispatched) {
            $dispatched[] = $args;
            return 'test-event-id';
        });

    $importer = new class extends OffersImporter {
        public function callDispatch(Product $product, float $newTotal, float $oldTotal): void
        {
            $this->dispatchAggregatedStockUpdated($product, $newTotal, $oldTotal);
        }
    };

    $importer->callDispatch($product, 42.0, 42.0);

    expect($dispatched)->toHaveCount(0);
});

test('ошибка публикации остатка не пробрасывается наружу (graceful)', function () {
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-001';

    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')->andThrow(new \Exception('RabbitMQ connection failed'));

    $importer = new class extends OffersImporter {
        public function callDispatch(Product $product, float $newTotal, float $oldTotal): void
        {
            $this->dispatchAggregatedStockUpdated($product, $newTotal, $oldTotal);
        }
    };

    $importer->callDispatch($product, 10.0, 0.0);

    expect(true)->toBeTrue();
});

test('RestsImporter использует тот же трейт публикации остатка', function () {
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 7;
    $product->external_id = 'prod-007';

    $dispatched = [];
    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) use (&$dispatched) {
            $dispatched[] = compact('eventType', 'payload');
            return 'test-event-id';
        });

    $importer = new class extends RestsImporter {
        public function callDispatch(Product $product, float $newTotal, float $oldTotal): void
        {
            $this->dispatchAggregatedStockUpdated($product, $newTotal, $oldTotal);
        }
    };

    $importer->callDispatch($product, 300.0, 100.0);

    expect($dispatched)->toHaveCount(1);
    expect($dispatched[0]['eventType'])->toBe('stock_updated');
    expect($dispatched[0]['payload']['quantity'])->toBe(300.0);
});
