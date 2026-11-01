<?php

use App\Models\ExchangeLog;
use App\Models\Image;
use App\Models\Product;
use App\Services\ProblemProductsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Создаёт товар, «чистый» по всем реквизитам; через флаги/overrides ломаем
 * ровно одно измерение, чтобы счётчики кодов не пересекались.
 */
function makeProblemProduct(array $overrides = [], bool $withImage = true, bool $withPrice = true, bool $withStock = true): Product
{
    $attrs = array_merge([
        'shop_id' => 1,
        'category_id' => null,
        'name' => 'Товар',
        'sku' => 'SKU-' . uniqid(),
        'barcode' => '4600000000001',
        'barcodes' => ['4600000000001'],
        'attributes' => ['unit' => 'шт'],
        'is_active' => true,
    ], $overrides);

    $product = Product::factory()->create($attrs);

    if ($withImage) {
        Image::factory()->create(['product_id' => $product->id]);
    }
    if ($withPrice) {
        $product->prices()->create(['price_type' => 'Розничная', 'value' => 100]);
    }
    if ($withStock) {
        $product->stocks()->create(['warehouse_id' => 'wh-1', 'quantity' => 5]);
    }

    return $product;
}

function entry(array $entries, string $code): ?array
{
    foreach ($entries as $e) {
        if ($e['code'] === $code) {
            return $e;
        }
    }

    return null;
}

test('catalog: missing_sku собирает только товары без артикула', function () {
    makeProblemProduct();
    makeProblemProduct();
    $broken = makeProblemProduct(['sku' => null]);

    $entries = ProblemProductsCollector::collect(ExchangeLog::TYPE_CATALOG_IMPORT);
    $sku = entry($entries, 'missing_sku');

    expect($sku)->not->toBeNull();
    expect($sku['count'])->toBe(1);
    expect($sku['products'])->toHaveCount(1);
    expect($sku['products'][0]['external_id'])->toBe($broken->external_id);
    // другие коды чистые
    expect(entry($entries, 'missing_name'))->toBeNull();
    expect(entry($entries, 'missing_barcode'))->toBeNull();
});

test('catalog: missing_name по пустой строке', function () {
    makeProblemProduct();
    makeProblemProduct(['name' => '']);
    makeProblemProduct(['name' => '']);

    $entries = ProblemProductsCollector::collect(ExchangeLog::TYPE_CATALOG_IMPORT);
    $name = entry($entries, 'missing_name');

    expect($name['count'])->toBe(2);
    expect($name['message'])->toBe('2 товаров без наименования');
});

test('catalog: missing_barcode когда пусты и barcode, и barcodes', function () {
    makeProblemProduct(); // есть barcode
    makeProblemProduct(['barcode' => null, 'barcodes' => null]);
    makeProblemProduct(['barcode' => '', 'barcodes' => []]);
    // есть barcode, но пустой barcodes — НЕ проблема (хватает одного)
    makeProblemProduct(['barcode' => '4600000000002', 'barcodes' => []]);

    $entries = ProblemProductsCollector::collect(ExchangeLog::TYPE_CATALOG_IMPORT);
    $bc = entry($entries, 'missing_barcode');

    expect($bc['count'])->toBe(2);
});

test('catalog: missing_unit когда нет attributes.unit', function () {
    makeProblemProduct(); // unit = шт
    makeProblemProduct(['attributes' => ['weight' => 1]]);
    makeProblemProduct(['attributes' => null]);

    $entries = ProblemProductsCollector::collect(ExchangeLog::TYPE_CATALOG_IMPORT);

    expect(entry($entries, 'missing_unit')['count'])->toBe(2);
});

test('catalog: missing_image — новый код для товаров без изображений', function () {
    makeProblemProduct(withImage: true);
    makeProblemProduct(withImage: true);
    $noImg = makeProblemProduct(withImage: false);

    $entries = ProblemProductsCollector::collect(ExchangeLog::TYPE_CATALOG_IMPORT);
    $img = entry($entries, 'missing_image');

    expect($img)->not->toBeNull();
    expect($img['count'])->toBe(1);
    expect($img['message'])->toBe('1 товаров без изображения');
    expect($img['products'][0]['external_id'])->toBe($noImg->external_id);
});

test('catalog: неактивные товары исключаются', function () {
    makeProblemProduct(['sku' => null, 'is_active' => false]);

    $entries = ProblemProductsCollector::collect(ExchangeLog::TYPE_CATALOG_IMPORT);

    expect(entry($entries, 'missing_sku'))->toBeNull();
});

test('offers: missing_price и missing_stock', function () {
    makeProblemProduct();
    $noPrice = makeProblemProduct(withPrice: false);
    $noStock = makeProblemProduct(withStock: false);

    $entries = ProblemProductsCollector::collect(ExchangeLog::TYPE_OFFERS_IMPORT);

    expect(entry($entries, 'missing_price')['count'])->toBe(1);
    expect(entry($entries, 'missing_price')['products'][0]['external_id'])->toBe($noPrice->external_id);
    expect(entry($entries, 'missing_stock')['count'])->toBe(1);
    expect(entry($entries, 'missing_stock')['products'][0]['external_id'])->toBe($noStock->external_id);
});

test('count всегда равен длине списка products', function () {
    foreach (range(1, 3) as $i) {
        makeProblemProduct(['sku' => null]);
    }

    $entries = ProblemProductsCollector::collect(ExchangeLog::TYPE_CATALOG_IMPORT);
    $sku = entry($entries, 'missing_sku');

    expect($sku['count'])->toBe(count($sku['products']));
    expect($sku['truncated'])->toBeFalse();
});

test('collect возвращает только коды с count > 0', function () {
    makeProblemProduct(); // всё чисто

    $entries = ProblemProductsCollector::collect(ExchangeLog::TYPE_CATALOG_IMPORT);

    expect($entries)->toBe([]);
});

test('codesForDataType отдаёт полный набор кодов включая нулевые', function () {
    expect(ProblemProductsCollector::codesForDataType(ExchangeLog::TYPE_CATALOG_IMPORT))
        ->toBe(['missing_name', 'missing_sku', 'missing_barcode', 'missing_unit', 'missing_image']);
    expect(ProblemProductsCollector::codesForDataType(ExchangeLog::TYPE_OFFERS_IMPORT))
        ->toBe(['missing_price', 'missing_stock']);
    expect(ProblemProductsCollector::codesForDataType(ExchangeLog::TYPE_RESTS_IMPORT))
        ->toBe([]);
});
