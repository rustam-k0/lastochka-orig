<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Витринная цена с приоритетом типов: если у товара нет «Розничной» цены
 * (как у ТСК — только ОПТ/Гасимов/закупка), брать следующий тип из
 * config('exchange1c.retail_price_types'). Иначе price=0 и товар без цены
 * на витрине Bagisto (инцидент 2026-06-09: у ТСК 0 розничных цен).
 */
beforeEach(function () {
    config()->set('exchange1c.retail_price_types', ['Розничная', 'ОПТ']);
});

function priceFallbackTransformer(): object
{
    return new class extends ProductEventTransformer {
        public function exposeGetPrice(Product $p): float
        {
            return $this->getPrice($p);
        }

        public function exposeGetDiscountPrice(Product $p): ?float
        {
            return $this->getDiscountPrice($p);
        }
    };
}

/**
 * @param array<string, float|array{value: float, discount?: float|null}> $prices
 */
function makeProductWithPrices(array $prices): Product
{
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    foreach ($prices as $type => $spec) {
        $spec = is_array($spec) ? $spec : ['value' => $spec];

        Price::create([
            'product_id' => $product->id,
            'price_type' => $type,
            'value' => $spec['value'],
            'discount_value' => $spec['discount'] ?? null,
            'currency' => 'RUB',
        ]);
    }

    return $product->fresh();
}

test('getPrice falls back to ОПТ when Розничная is missing', function () {
    $p = makeProductWithPrices(['ОПТ' => 85.0, 'закупка' => 70.0]);

    expect(priceFallbackTransformer()->exposeGetPrice($p))->toBe(85.0);
});

test('getPrice prefers Розничная over ОПТ when both present', function () {
    $p = makeProductWithPrices(['Розничная' => 100.0, 'ОПТ' => 85.0]);

    expect(priceFallbackTransformer()->exposeGetPrice($p))->toBe(100.0);
});

test('getPrice returns 0 when none of configured price types present', function () {
    $p = makeProductWithPrices(['закупка' => 70.0, 'Гасимов' => 90.0]);

    expect(priceFallbackTransformer()->exposeGetPrice($p))->toBe(0.0);
});

test('getDiscountPrice returns discount_value of the resolved retail price type', function () {
    $p = makeProductWithPrices([
        'Розничная' => ['value' => 100.0, 'discount' => 79.0],
        'ОПТ' => 85.0,
    ]);

    expect(priceFallbackTransformer()->exposeGetDiscountPrice($p))->toBe(79.0);
});

test('getDiscountPrice returns null when the retail price type has no discount', function () {
    $p = makeProductWithPrices(['Розничная' => 100.0]);

    expect(priceFallbackTransformer()->exposeGetDiscountPrice($p))->toBeNull();
});

test('getDiscountPrice falls back to single price type like getPrice does', function () {
    $p = makeProductWithPrices(['9243451b-d222-49a3-b0f8-134cce762863' => ['value' => 86.0, 'discount' => 69.0]]);

    expect(priceFallbackTransformer()->exposeGetDiscountPrice($p))->toBe(69.0);
});
