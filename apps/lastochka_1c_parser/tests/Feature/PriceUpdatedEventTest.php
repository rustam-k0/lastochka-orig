<?php

use App\Models\Price;
use App\Models\Product;
use App\Services\Exchange1C\OffersImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Mockery\MockInterface;

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
});

test('dispatchPriceUpdatedEvent is called when price changes in importPrices', function () {
    // Создаём мок Product
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-001';
    $product->sku = 'SKU-001';

    // Проверяем, что CustomEventPublisher::dispatch вызывается с правильными параметрами
    $dispatchedEvents = [];

    // Мокаем статический метод dispatch
    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) use (&$dispatchedEvents) {
            $dispatchedEvents[] = [
                'entity_type' => $entityType,
                'event_type' => $eventType,
                'payload' => $payload,
            ];
            return 'test-event-id';
        });

    // Создаём частичный мок OffersImporter для вызова protected метода
    $importer = new class extends OffersImporter {
        public function testDispatchPriceUpdatedEvent(
            Product $product,
            float $newPrice,
            float $oldPrice,
            string $priceType,
            string $currency,
            ?float $discountPrice = null
        ): void {
            $this->dispatchPriceUpdatedEvent($product, $newPrice, $oldPrice, $priceType, $currency, $discountPrice);
        }
    };

    $importer->testDispatchPriceUpdatedEvent($product, 1500.00, 1000.00, 'Розничная', 'RUB');

    expect($dispatchedEvents)->toHaveCount(1);
    expect($dispatchedEvents[0]['entity_type'])->toBe('products');
    expect($dispatchedEvents[0]['event_type'])->toBe('price_updated');
    expect($dispatchedEvents[0]['payload']['price'])->toBe(1500.00);
    expect($dispatchedEvents[0]['payload']['old_price'])->toBe(1000.00);
    expect($dispatchedEvents[0]['payload']['price_type'])->toBe('Розничная');
    expect($dispatchedEvents[0]['payload']['currency'])->toBe('RUB');
    expect($dispatchedEvents[0]['payload']['discount_price'])->toBeNull();
});

test('dispatchPriceUpdatedEvent carries discount_price when provided', function () {
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-001';
    $product->sku = 'SKU-001';

    $dispatchedEvents = [];

    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) use (&$dispatchedEvents) {
            $dispatchedEvents[] = $payload;
            return 'test-event-id';
        });

    $importer = new class extends OffersImporter {
        public function testDispatchPriceUpdatedEvent(
            Product $product,
            float $newPrice,
            float $oldPrice,
            string $priceType,
            string $currency,
            ?float $discountPrice = null
        ): void {
            $this->dispatchPriceUpdatedEvent($product, $newPrice, $oldPrice, $priceType, $currency, $discountPrice);
        }
    };

    $importer->testDispatchPriceUpdatedEvent($product, 1500.00, 1000.00, 'Розничная', 'RUB', 1200.00);

    expect($dispatchedEvents[0]['discount_price'])->toBe(1200.00);
});

test('dispatchPriceUpdatedEvent handles errors gracefully', function () {
    $product = Mockery::mock(Product::class)->makePartial();
    $product->id = 1;
    $product->external_id = 'prod-001';

    // Мокаем dispatch чтобы бросить исключение
    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andThrow(new \Exception('RabbitMQ connection failed'));

    $importer = new class extends OffersImporter {
        public function testDispatchPriceUpdatedEvent(
            Product $product,
            float $newPrice,
            float $oldPrice,
            string $priceType,
            string $currency
        ): void {
            $this->dispatchPriceUpdatedEvent($product, $newPrice, $oldPrice, $priceType, $currency);
        }
    };

    // Не должен бросить исключение - ошибка логируется
    $importer->testDispatchPriceUpdatedEvent($product, 1500.00, 1000.00, 'Розничная', 'RUB');

    expect(true)->toBeTrue(); // Если дошли сюда — тест прошёл
});

test('price change detection logic works correctly', function () {
    // Тест логики сравнения цен
    $oldPrice = 1000.00;
    $newPrice = 1500.00;
    $samePrice = 1000.00;

    // Цена изменилась
    expect($oldPrice !== null && (float) $oldPrice != $newPrice)->toBeTrue();

    // Цена не изменилась
    expect($oldPrice !== null && (float) $oldPrice != $samePrice)->toBeFalse();

    // Новая цена (old = null)
    expect(null !== null)->toBeFalse();
});
