<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * MID-308: 1c:resync-prices переиздаёт цену вместе с лесенкой. Сама команда ключ
 * price_tiers в dispatch() не кладёт: его добавляет buildPriceUpdatedPayload() из
 * БД, когда у товара есть ступени розничного типа. Итоговый payload собираем так же,
 * как CustomEventPublisher::publish(): dispatch-аргументы пропускаем через
 * ProductEventTransformer::toCustomEvent() (сам publisher в тестах парсера всегда
 * alias-мок, см. PriceDiscountImportTest.php).
 */
class ResyncPricesPriceTiersRecorder
{
    /** @var array<int, array{model: Product, payload: array<string, mixed>}> */
    public static array $calls = [];
}

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
    config()->set('exchange1c.retail_price_types', ['Розничная', 'ОПТ']);
    config()->set('exchange1c.cost_price_types', ['Закупочная']);

    ResyncPricesPriceTiersRecorder::$calls = [];

    $mock = Mockery::mock('alias:'.CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) {
            ResyncPricesPriceTiersRecorder::$calls[] = ['model' => $model, 'payload' => $payload];

            return 'test-event-id';
        });
});

/**
 * @return array<int, array<string, mixed>> итоговые payload-ы price_updated, как их собрал бы publisher
 */
function resyncPricesFinalPayloads(): array
{
    $transformer = new ProductEventTransformer();

    return array_map(
        fn (array $call): array => $transformer->toCustomEvent($call['model'], 'price_updated', $call['payload']),
        ResyncPricesPriceTiersRecorder::$calls
    );
}

test('resync-prices: событие розничной цены несёт сохранённую лесенку', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 5, 'value' => 199]);

    $exit = Artisan::call('1c:resync-prices', ['--shop-id' => 3]);

    $payloads = resyncPricesFinalPayloads();

    expect($exit)->toBe(0);
    expect($payloads)->toHaveCount(1);
    expect($payloads[0]['price'])->toBe(279.0);
    expect($payloads[0]['price_tiers'])->toBe([
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 199.0],
    ]);
});

test('resync-prices: у товара без ступеней ключа price_tiers нет', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);

    Artisan::call('1c:resync-prices', ['--shop-id' => 3]);

    $payloads = resyncPricesFinalPayloads();

    expect($payloads)->toHaveCount(1);
    expect($payloads[0])->not->toHaveKey('price_tiers');
});

test('resync-prices: событие нерозничного типа лесенку не несёт', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);
    Price::create(['product_id' => $product->id, 'price_type' => 'ОПТ', 'value' => 250, 'currency' => 'RUB']);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    Artisan::call('1c:resync-prices', ['--shop-id' => 3]);

    $byType = collect(resyncPricesFinalPayloads())->keyBy('price_type');

    expect($byType['Розничная']['price_tiers'])->toBe([['min_quantity' => 2.0, 'price' => 249.0]]);
    expect($byType['ОПТ'])->not->toHaveKey('price_tiers');
});

test('resync-prices подгружает ступени пачкой на чанк, а не запросом на каждый товар', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);

    Artisan::call('1c:resync-prices', ['--shop-id' => 3]);

    expect(ResyncPricesPriceTiersRecorder::$calls)->toHaveCount(1);
    expect(ResyncPricesPriceTiersRecorder::$calls[0]['model']->relationLoaded('priceTiers'))->toBeTrue();
});

/**
 * Fix round 1 (MID-308): предыдущий тест «подгружает ступени пачкой на чанк»
 * проверяет relationLoaded() только на ОДНОМ товаре и не отличает пакетную
 * загрузку ->with('priceTiers') от ленивой построчной — при одном товаре число
 * запросов к price_tiers одинаковое в обоих случаях. Здесь три товара в одном
 * чанке (два со ступенями, один без), и вместо relationLoaded() считаются сами
 * SELECT-запросы к таблице price_tiers за весь флоу (команда + сборка итоговых
 * payload-ов, как это делает publisher): ожидается ровно один на чанк, а не по
 * запросу на товар. Счётчик обязан охватывать и resyncPricesFinalPayloads(),
 * иначе тест не отличит батч от ленивой построчной загрузки: buildPriceUpdatedPayload()
 * реально обращается к priceTiers()/priceTiers только там, а не внутри самой команды.
 */
test('resync-prices делает ровно один SELECT к price_tiers на чанк из нескольких товаров', function () {
    $withTiersA = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $withTiersA->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);
    PriceTier::create(['product_id' => $withTiersA->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $withTiersB = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $withTiersB->id, 'price_type' => 'Розничная', 'value' => 199, 'currency' => 'RUB']);
    PriceTier::create(['product_id' => $withTiersB->id, 'price_type' => 'Розничная', 'min_quantity' => 3, 'value' => 179]);

    $withoutTiers = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $withoutTiers->id, 'price_type' => 'Розничная', 'value' => 99, 'currency' => 'RUB']);

    $priceTiersSelectQueries = 0;
    DB::listen(function ($query) use (&$priceTiersSelectQueries) {
        if (preg_match('/from\s+["`]?price_tiers["`]?/i', $query->sql) === 1) {
            $priceTiersSelectQueries++;
        }
    });

    Artisan::call('1c:resync-prices', ['--shop-id' => 3]);

    $byExternalId = collect(resyncPricesFinalPayloads())->keyBy('id');

    expect($priceTiersSelectQueries)->toBe(1);
    expect($byExternalId[$withTiersA->external_id]['price_tiers'])->toBe([['min_quantity' => 2.0, 'price' => 249.0]]);
    expect($byExternalId[$withTiersB->external_id]['price_tiers'])->toBe([['min_quantity' => 3.0, 'price' => 179.0]]);
    expect($byExternalId[$withoutTiers->external_id])->not->toHaveKey('price_tiers');
});

/**
 * P6 (MID-308, фикс-волна): getCostPrice() и resolveRetailPriceType() в
 * ProductEventTransformer читают цены через связь $product->prices (та же
 * relationLoaded('prices')-развилка, что у getPriceTiers()/priceTiers). Команда
 * не подгружает эту связь пачкой — buildPriceUpdatedPayload() дёргает
 * $product->prices()->get() заново на КАЖДЫЙ вызов (на каждую цену товара
 * со ступенями: getCostPrice() + resolveRetailPriceType() внутри getPriceTiers()).
 * На товаре с несколькими типами цен и лесенкой это даёт несколько лишних SELECT
 * к prices вместо одного пачкой на чанк, как уже сделано для price_tiers.
 *
 * Считаем SELECT к prices за ВЕСЬ флоу (команда + сборка итоговых payload-ов, как
 * делает publisher): ожидается не больше «число товаров + 1» (по одному
 * неизбежному SELECT команды на товар — Price::where(...)->get() с фильтром
 * по price-type, плюс один пакетный eager-load на чанк), а не кратно числу
 * типов цен на товар.
 */
test('resync-prices не даёт лишний SELECT к prices на товар со ступенями и несколькими типами цен', function () {
    config()->set('exchange1c.cost_price_types', ['Закупочная']);

    $productA = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $productA->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);
    Price::create(['product_id' => $productA->id, 'price_type' => 'Закупочная', 'value' => 150, 'currency' => 'RUB']);
    PriceTier::create(['product_id' => $productA->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $productB = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $productB->id, 'price_type' => 'Розничная', 'value' => 199, 'currency' => 'RUB']);
    Price::create(['product_id' => $productB->id, 'price_type' => 'Закупочная', 'value' => 120, 'currency' => 'RUB']);
    PriceTier::create(['product_id' => $productB->id, 'price_type' => 'Розничная', 'min_quantity' => 3, 'value' => 179]);

    // Якорим regex на начало строки: "select ... from prices", а не любое
    // упоминание "prices" — иначе считает и подзапрос ...->whereIn('id',
    // Price::query()->select('product_id')) внутри SELECT из "products".
    $pricesSelectQueries = 0;
    DB::listen(function ($query) use (&$pricesSelectQueries) {
        if (preg_match('/^select \* from ["`]?prices["`]?/i', $query->sql) === 1) {
            $pricesSelectQueries++;
        }
    });

    Artisan::call('1c:resync-prices', ['--shop-id' => 3]);

    resyncPricesFinalPayloads();

    expect($pricesSelectQueries)->toBeLessThanOrEqual(3);
});
