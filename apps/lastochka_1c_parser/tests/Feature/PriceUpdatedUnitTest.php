<?php

use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;

/**
 * price_updated должен нести единицу измерения товара (measurement_unit/unit_type),
 * чтобы admin_back само-исцелял base_unit_type_id и не раздувал цену в 1000 раз
 * для кг-товаров с залипшей единицей «грамм» (LASTOCHKI-JAMI-11200).
 */
beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
});

test('price_updated payload carries kilogram unit for ОКЕИ 166', function () {
    $transformer = new ProductEventTransformer();

    $product = new Product();
    $product->id = 5129;
    $product->external_id = 'f2737ed6-a755-11e7-8ece-001517b3ea47';
    $product->sku = '369';
    $product->attributes = ['unit' => '166']; // ОКЕИ 166 — килограмм

    $payload = $transformer->toCustomEvent($product, 'price_updated', ['price' => 113.00]);

    expect($payload)->not->toBeNull();
    expect($payload['event_type'])->toBe('price_updated');
    expect($payload['price'])->toBe(113.00);
    expect($payload['measurement_unit'])->toBe('kilogram');
    expect($payload['unit_type'])->toBe('kg');
});

test('price_updated payload carries gram unit for ОКЕИ 163', function () {
    $transformer = new ProductEventTransformer();

    $product = new Product();
    $product->id = 7;
    $product->external_id = 'g-1';
    $product->sku = 'G1';
    $product->attributes = ['unit' => '163']; // ОКЕИ 163 — грамм

    $payload = $transformer->toCustomEvent($product, 'price_updated', ['price' => 5.0]);

    expect($payload['measurement_unit'])->toBe('gram');
    expect($payload['unit_type'])->toBe('g');
});

test('price_updated payload falls back to piece when unit is missing', function () {
    $transformer = new ProductEventTransformer();

    $product = new Product();
    $product->id = 1;
    $product->external_id = 'p-1';
    $product->sku = 'P1';

    $payload = $transformer->toCustomEvent($product, 'price_updated', ['price' => 10.0]);

    expect($payload['measurement_unit'])->toBe('piece');
    expect($payload['unit_type'])->toBe('piece');
});
