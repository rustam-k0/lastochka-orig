<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * MID-308: ключ price_tiers в событиях товара. Ключ появляется только у товаров,
 * у которых есть ступени розничного типа цены (тот же выбор типа, что у getPrice()).
 * У остальных товаров payload не меняется ни на один ключ: форки, где 1С не шлёт
 * <ЦеныОтКоличества>, продолжают получать прежние события.
 */
beforeEach(function () {
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
    config()->set('exchange1c.retail_price_types', ['Розничная', 'ОПТ']);
    config()->set('exchange1c.cost_price_types', ['Закупочная']);
});

/**
 * @param  array<string, float>  $prices  [price_type => value]
 * @param  array<string, array<int, array{0: float, 1: float}>>  $tiers  [price_type => [[min_quantity, value], ...]]
 */
function makePriceTiersEventProduct(array $prices, array $tiers = []): Product
{
    $product = Product::create([
        'external_id' => 'ext-'.uniqid(),
        'name' => 'Тестовый товар',
        'sku' => 'SKU-'.uniqid(),
        'is_active' => true,
        'shop_id' => 1,
    ]);

    foreach ($prices as $type => $value) {
        Price::create(['product_id' => $product->id, 'price_type' => $type, 'value' => $value, 'currency' => 'RUB']);
    }

    foreach ($tiers as $type => $rows) {
        foreach ($rows as [$minQuantity, $value]) {
            PriceTier::create([
                'product_id' => $product->id,
                'price_type' => $type,
                'min_quantity' => $minQuantity,
                'value' => $value,
            ]);
        }
    }

    return $product->fresh();
}

function priceTiersRetailPayload(float $price = 279.0): array
{
    return ['price' => $price, 'old_price' => 0.0, 'price_type' => 'Розничная', 'currency' => 'RUB', 'discount_price' => null];
}

test('price_updated товара без ступеней: ключа price_tiers нет, состав payload прежний', function () {
    $product = makePriceTiersEventProduct(['Розничная' => 279.0]);

    $payload = (new ProductEventTransformer())->toCustomEvent($product, 'price_updated', priceTiersRetailPayload());

    expect(array_keys($payload))->toBe([
        'event_id', 'event_type', 'event_timestamp', 'id', 'shop_code', 'source', 'sku',
        'price', 'old_price', 'price_type', 'currency', 'discount_price', 'cost_price',
        'unit_type', 'measurement_unit',
    ]);
});

test('price_updated товара со ступенями розничного типа несёт лесенку по возрастанию порога', function () {
    $product = makePriceTiersEventProduct(
        ['Розничная' => 279.0],
        ['Розничная' => [[5, 199], [2, 249]]]
    );

    $payload = (new ProductEventTransformer())->toCustomEvent($product, 'price_updated', priceTiersRetailPayload());

    expect($payload['price_tiers'])->toBe([
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 199.0],
    ]);
});

test('явный price_tiers из dispatch приоритетнее БД, включая пустой массив', function () {
    $product = makePriceTiersEventProduct(['Розничная' => 279.0], ['Розничная' => [[2, 249]]]);
    $transformer = new ProductEventTransformer();

    $cleared = $transformer->toCustomEvent($product, 'price_updated', priceTiersRetailPayload() + ['price_tiers' => []]);
    $replaced = $transformer->toCustomEvent($product, 'price_updated', priceTiersRetailPayload() + [
        'price_tiers' => [['min_quantity' => 3.0, 'price' => 240.0]],
    ]);

    expect($cleared)->toHaveKey('price_tiers');
    expect($cleared['price_tiers'])->toBe([]);
    expect($replaced['price_tiers'])->toBe([['min_quantity' => 3.0, 'price' => 240.0]]);
});

test('ступени нерозничного типа в событие не попадают', function () {
    $product = makePriceTiersEventProduct(
        ['Розничная' => 279.0, 'ОПТ' => 250.0, 'Закупочная' => 150.0],
        ['ОПТ' => [[10, 230]], 'Закупочная' => [[100, 140]]]
    );
    $transformer = new ProductEventTransformer();

    $retailEvent = $transformer->toCustomEvent($product, 'price_updated', priceTiersRetailPayload());
    $wholesaleEvent = $transformer->toCustomEvent($product, 'price_updated', [
        'price' => 250.0, 'old_price' => 240.0, 'price_type' => 'ОПТ', 'currency' => 'RUB',
    ]);

    expect($retailEvent)->not->toHaveKey('price_tiers');
    expect($wholesaleEvent)->not->toHaveKey('price_tiers');
    expect($transformer->toEvent($product))->not->toHaveKey('price_tiers');
});

test('событие нерозничного типа не несёт лесенку розничного', function () {
    $product = makePriceTiersEventProduct(
        ['Розничная' => 279.0, 'ОПТ' => 250.0],
        ['Розничная' => [[2, 249]]]
    );

    $payload = (new ProductEventTransformer())->toCustomEvent($product, 'price_updated', [
        'price' => 250.0, 'old_price' => 240.0, 'price_type' => 'ОПТ', 'currency' => 'RUB',
    ]);

    expect($payload)->not->toHaveKey('price_tiers');
});

test('розничным считается следующий тип из retail_price_types, если первого нет', function () {
    $product = makePriceTiersEventProduct(['ОПТ' => 250.0], ['ОПТ' => [[10, 230]]]);
    $transformer = new ProductEventTransformer();

    expect($transformer->resolveRetailPriceType($product))->toBe('ОПТ');
    expect($transformer->toEvent($product)['price_tiers'])->toBe([['min_quantity' => 10.0, 'price' => 230.0]]);
});

test('единственный тип цены с GUID вместо имени считается розничным', function () {
    $product = makePriceTiersEventProduct(['guid-price-type' => 279.0], ['guid-price-type' => [[2, 249]]]);
    $transformer = new ProductEventTransformer();

    $payload = $transformer->toCustomEvent($product, 'price_updated', [
        'price' => 279.0, 'old_price' => 0.0, 'price_type' => 'guid-price-type', 'currency' => 'RUB',
    ]);

    expect($transformer->resolveRetailPriceType($product))->toBe('guid-price-type');
    expect($payload['price_tiers'])->toBe([['min_quantity' => 2.0, 'price' => 249.0]]);
});

test('toEvent: ключ price_tiers только у товара со ступенями розничного типа', function () {
    $transformer = new ProductEventTransformer();
    $withTiers = makePriceTiersEventProduct(['Розничная' => 279.0], ['Розничная' => [[2, 249], [5, 199]]]);
    $withoutTiers = makePriceTiersEventProduct(['Розничная' => 279.0]);

    expect($transformer->toEvent($withTiers)['price_tiers'])->toBe([
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 199.0],
    ]);
    expect($transformer->toEvent($withoutTiers))->not->toHaveKey('price_tiers');
});

test('несохранённый товар не обращается к БД за ступенями', function () {
    $product = new Product();
    $product->id = 1;
    $product->external_id = 'p-1';
    $product->sku = 'P1';

    $payload = (new ProductEventTransformer())->toCustomEvent($product, 'price_updated', ['price' => 10.0]);

    expect($payload)->not->toHaveKey('price_tiers');
});

/**
 * Закрепляющий тест (MID-308, довесок по итогам ревью): выбор розничного типа
 * цены согласован с ценой, которую трансформер публикует в событии. getPrice()
 * protected, поэтому сравниваем с полем price в toEvent(), как и предполагает
 * resolveRetailPriceType() — тот же приоритет типов, что у getPrice().
 */
test('выбор розничного типа согласован с ценой в toEvent() для нескольких конфигураций', function () {
    $transformer = new ProductEventTransformer();

    // Несколько типов цены: приоритет по config('exchange1c.retail_price_types').
    $multiType = makePriceTiersEventProduct(['ОПТ' => 250.0, 'Розничная' => 279.0]);
    expect($transformer->resolveRetailPriceType($multiType))->toBe('Розничная');
    expect($transformer->toEvent($multiType)['price'])->toBe(279.0);

    // Единственный тип цены не из списка розничных: fallback на единственный тип.
    $fallback = makePriceTiersEventProduct(['guid-price-type' => 199.0]);
    expect($transformer->resolveRetailPriceType($fallback))->toBe('guid-price-type');
    expect($transformer->toEvent($fallback)['price'])->toBe(199.0);

    // Цен нет вовсе: розничного типа тоже нет.
    $noPrices = makePriceTiersEventProduct([]);
    expect($transformer->resolveRetailPriceType($noPrices))->toBeNull();
});

/**
 * Закрепляющий тест (MID-308, довесок по итогам ревью): состав toEvent() у товара
 * без ступеней побайтно тот же, что был до фичи price_tiers — ключа price_tiers
 * нет, остальные ключи и их порядок не изменились.
 */
test('toEvent() товара без ступеней: состав ключей payload не меняется', function () {
    $product = makePriceTiersEventProduct(['Розничная' => 279.0]);

    $payload = (new ProductEventTransformer())->toEvent($product);

    expect(array_keys($payload))->toBe([
        'event_id', 'event_timestamp', 'id', 'shop_code', 'source', 'sku', 'code', 'plu',
        'barcodes', 'title', 'price', 'discount_price', 'cost_price', 'image_url', 'quantity',
        'unit_type', 'measurement_unit', 'description', 'meta_title', 'meta_description',
        'meta_keywords', 'weight', 'nds', 'attributes', 'category_1c_external_id',
        'created_at', 'updated_at', 'deleted_at',
    ]);
});

/**
 * Закрепляющий тест (MID-308, довесок по итогам ревью): несохранённая модель
 * new Product() не должна обращаться к БД вообще при сборке price_updated, а не
 * только избегать ключа price_tiers, как проверяет тест выше.
 */
test('несохранённая модель не делает ни одного SQL-запроса при сборке price_updated', function () {
    $product = new Product();
    $product->id = 1;
    $product->external_id = 'p-1';
    $product->sku = 'P1';

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    (new ProductEventTransformer())->toCustomEvent($product, 'price_updated', ['price' => 10.0]);

    expect($queries)->toBe(0);
});
