<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Stock;
use App\Services\Exchange1C\RestsImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * RestsImporter должен принимать ОБА формата rests___*.xml:
 *
 * 1) Складской (Аладдин и др. форки) — количество в разрезе склада:
 *    <Остаток><Склад><Ид>wh</Ид><Количество>10</Количество></Склад></Остаток>
 *
 * 2) Бесскладовый (Ласточка с 13.05.2026) — количество прямо в <Остаток>:
 *    <Остаток><Количество>39</Количество></Остаток>
 *
 * Регрессия 13.05.2026: importStocks() жёстко требовал <Склад> и делал continue
 * на бессклАдовом формате → stocks не обновлялись → на сайте у всех товаров нули.
 */
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
 * Пишет rests___*.xml с переданными <Предложение>…</Предложение> во временный файл.
 */
function writeRestsXml(string $offersInner): string
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация xmlns="urn:1C.ru:commerceml_2" ВерсияСхемы="2.10">
    <ПакетПредложений СодержитТолькоИзменения="true">
        <Предложения>
            {$offersInner}
        </Предложения>
    </ПакетПредложений>
</КоммерческаяИнформация>
XML;

    $dir = sys_get_temp_dir() . '/1cExchange';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $path = $dir . '/rests___' . uniqid() . '.xml';
    file_put_contents($path, $xml);

    return $path;
}

function seedProduct(string $externalId): Product
{
    return Product::factory()->withoutCategory()->create([
        'external_id' => $externalId,
        'shop_id' => 1,
    ]);
}

test('imports warehouse-less stock into a single Общий row (Lastochka format)', function () {
    $product = seedProduct('prod-warehouseless');

    $path = writeRestsXml(
        '<Предложение><Ид>prod-warehouseless</Ид>'
        . '<Остатки><Остаток><Количество>39</Количество></Остаток></Остатки>'
        . '</Предложение>'
    );

    (new RestsImporter(1))->import($path);

    $stocks = Stock::where('product_id', $product->id)->get();

    expect($stocks)->toHaveCount(1);
    expect($stocks->first()->warehouse_id)->toBeNull();
    expect($stocks->first()->warehouse_name)->toBe('Общий');
    expect((float) $stocks->first()->quantity)->toBe(39.0);

    @unlink($path);
});

test('still imports warehouse-scoped stock per warehouse (Aladdin regression)', function () {
    $product = seedProduct('prod-warehouse');

    $path = writeRestsXml(
        '<Предложение><Ид>prod-warehouse</Ид>'
        . '<Остатки><Остаток><Склад><Ид>wh-1</Ид><Количество>10</Количество></Склад></Остаток></Остатки>'
        . '</Предложение>'
    );

    (new RestsImporter(1))->import($path);

    $stock = Stock::where('product_id', $product->id)->sole();

    expect($stock->warehouse_id)->toBe('wh-1');
    expect((float) $stock->quantity)->toBe(10.0);

    @unlink($path);
});

test('updates the existing Общий row in place instead of duplicating (no double count)', function () {
    $product = seedProduct('prod-update');
    Stock::create([
        'product_id' => $product->id,
        'warehouse_id' => null,
        'warehouse_name' => 'Общий',
        'quantity' => 5,
        'synced_at' => now(),
    ]);

    $path = writeRestsXml(
        '<Предложение><Ид>prod-update</Ид>'
        . '<Остатки><Остаток><Количество>2</Количество></Остаток></Остатки>'
        . '</Предложение>'
    );

    (new RestsImporter(1))->import($path);

    $stocks = Stock::where('product_id', $product->id)->get();

    expect($stocks)->toHaveCount(1);
    expect((float) $stocks->first()->quantity)->toBe(2.0);

    @unlink($path);
});

test('stores negative warehouse-less quantity as-is', function () {
    $product = seedProduct('prod-negative');

    $path = writeRestsXml(
        '<Предложение><Ид>prod-negative</Ид>'
        . '<Остатки><Остаток><Количество>-1</Количество></Остаток></Остатки>'
        . '</Предложение>'
    );

    (new RestsImporter(1))->import($path);

    expect((float) Stock::where('product_id', $product->id)->sole()->quantity)->toBe(-1.0);

    @unlink($path);
});

test('parses comma decimal in warehouse-less quantity', function () {
    $product = seedProduct('prod-comma');

    $path = writeRestsXml(
        '<Предложение><Ид>prod-comma</Ид>'
        . '<Остатки><Остаток><Количество>2,5</Количество></Остаток></Остатки>'
        . '</Предложение>'
    );

    (new RestsImporter(1))->import($path);

    expect((float) Stock::where('product_id', $product->id)->sole()->quantity)->toBe(2.5);

    @unlink($path);
});

test('dispatches one aggregated stock_updated event for warehouse-less format', function () {
    seedProduct('prod-event');

    $path = writeRestsXml(
        '<Предложение><Ид>prod-event</Ид>'
        . '<Остатки><Остаток><Количество>7</Количество></Остаток></Остатки>'
        . '</Предложение>'
    );

    (new RestsImporter(1))->import($path);

    expect($this->dispatched)->toHaveCount(1);
    expect($this->dispatched[0]['eventType'])->toBe('stock_updated');
    expect((float) $this->dispatched[0]['payload']['quantity'])->toBe(7.0);
    expect((float) $this->dispatched[0]['payload']['old_quantity'])->toBe(0.0);

    @unlink($path);
});
