<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use App\Services\Exchange1C\OffersImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * MID-308 (P2). На форках, где реквизит <ЦеныОтКоличества> не выгружается вовсе,
 * OffersImporter::syncPriceTiers() не должен делать SELECT к price_tiers на КАЖДОЕ
 * предложение (полный каталог приходит каждые несколько минут). Если во всей
 * таблице нет ни одной строки, у товара их точно тоже нет — короткое замыкание
 * должно сработать без обращения к price_tiers по конкретному товару.
 */
beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
    config()->set('exchange1c.retail_price_types', ['Розничная', 'ОПТ']);
    config()->set('exchange1c.cost_price_types', ['Закупочная']);

    $mock = Mockery::mock('alias:'.CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')->andReturn('test-event-id');
});

function noTagOfferXml(string $productId, string $price): SimpleXMLElement
{
    $xml = <<<XML
    <Предложение>
        <Ид>{$productId}</Ид>
        <Цены>
            <Цена>
                <ИдТипаЦены>Розничная</ИдТипаЦены>
                <ЦенаЗаЕдиницу>{$price}</ЦенаЗаЕдиницу>
                <Валюта>RUB</Валюта>
            </Цена>
        </Цены>
    </Предложение>
    XML;

    return new SimpleXMLElement($xml);
}

function tagOfferXml(string $productId, string $price, string $tierQuantity, string $tierPrice): SimpleXMLElement
{
    $xml = <<<XML
    <Предложение>
        <Ид>{$productId}</Ид>
        <Цены>
            <Цена>
                <ИдТипаЦены>Розничная</ИдТипаЦены>
                <ЦенаЗаЕдиницу>{$price}</ЦенаЗаЕдиницу>
                <ЦеныОтКоличества>
                    <ЦенаОтКоличества>
                        <Количество>{$tierQuantity}</Количество>
                        <ЦенаЗаЕдиницу>{$tierPrice}</ЦенаЗаЕдиницу>
                    </ЦенаОтКоличества>
                </ЦеныОтКоличества>
                <Валюта>RUB</Валюта>
            </Цена>
        </Цены>
    </Предложение>
    XML;

    return new SimpleXMLElement($xml);
}

function queryCountImporter(): OffersImporter
{
    return new class extends OffersImporter
    {
        public function exposeImportPrices(Product $product, SimpleXMLElement $offer): void
        {
            $this->importPrices($product, $offer);
        }
    };
}

function countPriceTiersSelectQueries(callable $callback): int
{
    $count = 0;
    $listener = function ($query) use (&$count) {
        if (preg_match('/from\s+["`]?price_tiers["`]?/i', $query->sql) === 1) {
            $count++;
        }
    };

    DB::listen($listener);
    $callback();

    return $count;
}

test('пустая таблица price_tiers: три предложения без тега в одном экземпляре импортёра дают не больше одного SELECT к price_tiers', function () {
    $productA = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $productA->id, 'price_type' => 'Розничная', 'value' => 100, 'currency' => 'RUB']);
    $productB = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $productB->id, 'price_type' => 'Розничная', 'value' => 200, 'currency' => 'RUB']);
    $productC = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $productC->id, 'price_type' => 'Розничная', 'value' => 300, 'currency' => 'RUB']);

    $importer = queryCountImporter();

    $queries = countPriceTiersSelectQueries(function () use ($importer, $productA, $productB, $productC) {
        $importer->exposeImportPrices($productA, noTagOfferXml((string) $productA->external_id, '101'));
        $importer->exposeImportPrices($productB, noTagOfferXml((string) $productB->external_id, '201'));
        $importer->exposeImportPrices($productC, noTagOfferXml((string) $productC->external_id, '301'));
    });

    expect($queries)->toBeLessThanOrEqual(1);
});

test('непустая таблица price_tiers: очистка «тег пропал» у другого товара по-прежнему работает', function () {
    $withTiers = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $withTiers->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);
    PriceTier::create(['product_id' => $withTiers->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $withoutTiers = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $withoutTiers->id, 'price_type' => 'Розничная', 'value' => 199, 'currency' => 'RUB']);

    $importer = queryCountImporter();

    // Таблица price_tiers не пуста (есть строка у $withTiers) — короткое замыкание
    // P2 не должно применяться, тег всё ещё должен корректно очищать лесенку.
    $importer->exposeImportPrices($withTiers, noTagOfferXml((string) $withTiers->external_id, '279'));
    $importer->exposeImportPrices($withoutTiers, noTagOfferXml((string) $withoutTiers->external_id, '199'));

    expect(PriceTier::where('product_id', $withTiers->id)->count())->toBe(0);
    expect(PriceTier::where('product_id', $withoutTiers->id)->count())->toBe(0);
});

test('тег пришёл при пустой таблице: путь прежний, лесенка сохраняется', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);

    $importer = queryCountImporter();
    $importer->exposeImportPrices($product, tagOfferXml((string) $product->external_id, '279', '2', '249'));

    expect(PriceTier::where('product_id', $product->id)->count())->toBe(1);
});
