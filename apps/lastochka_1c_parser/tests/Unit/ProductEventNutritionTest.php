<?php

use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Tests\TestCase;

uses(TestCase::class);

function callBuildNutritionAttributes(Product $product): array
{
    $transformer = new ProductEventTransformer();
    $method = new ReflectionMethod(ProductEventTransformer::class, 'buildNutritionAttributes');
    $method->setAccessible(true);

    return $method->invoke($transformer, $product);
}

test('buildNutritionAttributes builds {code,name,value} entries from attributes JSON', function () {
    $product = new Product([
        'external_id' => 'p-1',
        'name' => 'Булочка',
        'attributes' => [
            'unit' => '796',
            'composition' => 'мука, яйцо, сахар',
            'calories' => '250',
            'proteins' => '5.2',
            'fats' => '12',
            'carbs' => '30.5',
        ],
    ]);

    $attributes = callBuildNutritionAttributes($product);

    expect($attributes)->toBeArray()->toHaveCount(5);

    $byCode = collect($attributes)->keyBy('code');

    expect($byCode['composition'])->toBe(['code' => 'composition', 'name' => 'Состав', 'value' => 'мука, яйцо, сахар'])
        ->and($byCode['calories'])->toBe(['code' => 'calories', 'name' => 'Ккал', 'value' => '250'])
        ->and($byCode['proteins']['name'])->toBe('Белки')
        ->and($byCode['fats']['name'])->toBe('Жиры')
        ->and($byCode['carbs']['name'])->toBe('Углеводы');
});

test('buildNutritionAttributes trims values', function () {
    $product = new Product([
        'external_id' => 'p-trim',
        'name' => 'Сок',
        'attributes' => ['composition' => '  яблоко, вода  '],
    ]);

    expect(callBuildNutritionAttributes($product)[0]['value'])->toBe('яблоко, вода');
});

test('buildNutritionAttributes skips empty/missing nutrition values', function () {
    $product = new Product([
        'external_id' => 'p-2',
        'name' => 'Вода',
        'attributes' => [
            'composition' => 'вода',
            'calories' => '',
            'proteins' => '   ',
            // fats/carbs отсутствуют
        ],
    ]);

    $attributes = callBuildNutritionAttributes($product);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]['code'])->toBe('composition');
});

test('buildNutritionAttributes returns empty array when product has no nutrition', function () {
    $product = new Product([
        'external_id' => 'p-3',
        'name' => 'Гвозди',
        'attributes' => ['unit' => '796', 'weight' => '100'],
    ]);

    expect(callBuildNutritionAttributes($product))->toBe([]);
});

test('toEvent payload includes attributes[] with composition and КБЖУ', function () {
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    $product = new Product([
        'external_id' => 'p-4',
        'name' => 'Печенье',
        'is_active' => true,
        'attributes' => [
            'composition' => 'мука, сахар',
            'calories' => '400',
        ],
    ]);

    // Подменяем relations загруженными пустышками — toEvent не уходит в БД
    // (в проекте SQLite ломается на MySQL-only миграции, RefreshDatabase нельзя).
    $product->setRelation('prices', collect());
    $product->setRelation('stocks', collect());
    $product->setRelation('mainImage', null);
    $product->setRelation('category', null);
    $product->setRelation('shop', null);

    $payload = (new ProductEventTransformer())->toEvent($product);

    expect($payload)->toHaveKey('attributes');

    $byCode = collect($payload['attributes'])->keyBy('code');

    expect($byCode->keys()->all())->toContain('composition', 'calories')
        ->and($byCode['composition']['value'])->toBe('мука, сахар')
        ->and($byCode['calories']['value'])->toBe('400');
});
