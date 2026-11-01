<?php

use App\Models\Price;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('rabbitmq-events.shop_code', 'lastochka');
    config()->set('exchange1c.retail_price_types', ['Розничная']);
    config()->set('exchange1c.cost_price_types', ['Закупочная', 'Закупка']);
});

/**
 * Хелпер создания товара с набором цен по типам.
 * Имя отличается от makeProductWithPrices (ProductPriceFallbackTest) во
 * избежание redeclare-конфликта глобальных функций Pest при прогоне сьютом.
 *
 * @param array<string, float> $prices [price_type => value]
 */
function makeCostPriceProduct(array $prices): Product
{
    $product = Product::create([
        'external_id' => 'ext-' . uniqid(),
        'name' => 'Тестовый товар',
        'sku' => 'SKU-' . uniqid(),
        'is_active' => true,
        'shop_id' => 1,
    ]);
    foreach ($prices as $type => $value) {
        Price::create([
            'product_id' => $product->id,
            'price_type' => $type,
            'value' => $value,
            'currency' => 'RUB',
        ]);
    }

    return $product->fresh();
}

test('toEvent включает cost_price из закупочного типа цены', function () {
    $product = makeCostPriceProduct(['Розничная' => 150.00, 'Закупочная' => 90.50]);

    $transformer = new \App\RabbitMQ\Transformers\ProductEventTransformer();
    $payload = $transformer->toEvent($product);

    expect($payload['price'])->toBe(150.00);
    expect($payload['cost_price'])->toBe(90.50);
});

test('toEvent отдаёт cost_price = null когда закупочной цены нет', function () {
    $product = makeCostPriceProduct(['Розничная' => 150.00]);

    $transformer = new \App\RabbitMQ\Transformers\ProductEventTransformer();
    $payload = $transformer->toEvent($product);

    expect($payload['cost_price'])->toBeNull();
});

test('price_updated payload включает cost_price', function () {
    $product = makeCostPriceProduct(['Розничная' => 150.00, 'Закупочная' => 90.50]);

    $transformer = new \App\RabbitMQ\Transformers\ProductEventTransformer();
    $payload = $transformer->toCustomEvent($product, 'price_updated', [
        'price' => 150.00, 'old_price' => 140.00, 'price_type' => 'Розничная', 'currency' => 'RUB',
    ]);

    expect($payload['cost_price'])->toBe(90.50);
});
