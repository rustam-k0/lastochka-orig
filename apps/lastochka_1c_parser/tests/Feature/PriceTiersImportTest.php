<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use App\Services\Exchange1C\OffersImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

/**
 * MID-308: цены от количества. Кастомный реквизит <ЦеныОтКоличества> внутри блока
 * <Цена>, рядом с <ЦенаЗаЕдиницу> и <ЦенаЗаЕдиницуСоСкидкой>. Лесенка хранится в
 * price_tiers по (товар, тип цены); price_updated с ключом price_tiers уходит только
 * при изменении лесенки розничного типа, включая [] при исчезновении тега. У товара
 * без ступеней и без тега сырой payload dispatch() не меняется ни на один ключ.
 *
 * Alias-мок CustomEventPublisher заводится в beforeEach на весь файл, как в
 * PriceDiscountImportTest.php (иначе "class already exists" при прогоне сьютом).
 */
class PriceTiersImportRecorder
{
    /** @var array<int, array<string, mixed>> */
    public static array $payloads = [];
}

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
    config()->set('exchange1c.retail_price_types', ['Розничная', 'ОПТ']);
    config()->set('exchange1c.cost_price_types', ['Закупочная']);

    PriceTiersImportRecorder::$payloads = [];

    $mock = Mockery::mock('alias:'.CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) {
            PriceTiersImportRecorder::$payloads[] = $payload;

            return 'test-event-id';
        });
});

/**
 * @param  array<int, array{0: string, 1: string}>|null  $tiers  [[Количество, ЦенаЗаЕдиницу], ...]; null = тега нет
 */
function priceTiersOfferXml(string $productId, string $price, ?array $tiers, string $priceTypeId = 'Розничная'): SimpleXMLElement
{
    $tiersTag = '';

    if ($tiers !== null) {
        $rows = '';
        foreach ($tiers as [$quantity, $tierPrice]) {
            $rows .= "<ЦенаОтКоличества><Количество>{$quantity}</Количество><ЦенаЗаЕдиницу>{$tierPrice}</ЦенаЗаЕдиницу></ЦенаОтКоличества>";
        }
        $tiersTag = "<ЦеныОтКоличества>{$rows}</ЦеныОтКоличества>";
    }

    $xml = <<<XML
    <Предложение>
        <Ид>{$productId}</Ид>
        <Цены>
            <Цена>
                <ИдТипаЦены>{$priceTypeId}</ИдТипаЦены>
                <ЦенаЗаЕдиницу>{$price}</ЦенаЗаЕдиницу>
                {$tiersTag}
                <Валюта>RUB</Валюта>
            </Цена>
        </Цены>
    </Предложение>
    XML;

    return new SimpleXMLElement($xml);
}

function priceTiersImporter(): OffersImporter
{
    return new class extends OffersImporter
    {
        public function exposeImportPrices(Product $product, SimpleXMLElement $offer): void
        {
            $this->importPrices($product, $offer);
        }
    };
}

function makePriceTiersImportProduct(array $prices = ['Розничная' => 279.0]): Product
{
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);

    foreach ($prices as $type => $value) {
        Price::create(['product_id' => $product->id, 'price_type' => $type, 'value' => $value, 'currency' => 'RUB']);
    }

    return $product;
}

function storedPriceTiers(Product $product, string $priceType = 'Розничная'): array
{
    return PriceTier::where('product_id', $product->id)
        ->where('price_type', $priceType)
        ->orderBy('min_quantity')
        ->get()
        ->map(fn (PriceTier $tier): array => [$tier->min_quantity, $tier->value])
        ->all();
}

test('лесенка разбирается, сохраняется и уходит в price_updated, даже если цена не изменилась', function () {
    $product = makePriceTiersImportProduct();
    $offer = priceTiersOfferXml((string) $product->external_id, '279', [['5', '199'], ['2,5', '249,50']]);

    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toBe([[2.5, 249.5], [5.0, 199.0]]);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0]['price'])->toBe(279.0);
    expect(PriceTiersImportRecorder::$payloads[0]['old_price'])->toBe(279.0);
    expect(PriceTiersImportRecorder::$payloads[0]['price_tiers'])->toBe([
        ['min_quantity' => 2.5, 'price' => 249.5],
        ['min_quantity' => 5.0, 'price' => 199.0],
    ]);
});

test('та же лесенка при той же цене события не порождает', function () {
    $product = makePriceTiersImportProduct();
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 5, 'value' => 199]);

    $offer = priceTiersOfferXml((string) $product->external_id, '279', [['2', '249.00'], ['5.000', '199']]);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(0);
    expect(storedPriceTiers($product))->toBe([[2.0, 249.0], [5.0, 199.0]]);
});

test('изменение одной ступени перезаписывает лесенку и публикует её целиком', function () {
    $product = makePriceTiersImportProduct();
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 5, 'value' => 199]);

    $offer = priceTiersOfferXml((string) $product->external_id, '279', [['2', '249'], ['5', '189']]);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toBe([[2.0, 249.0], [5.0, 189.0]]);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0]['price_tiers'])->toBe([
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 189.0],
    ]);
});

test('тег пропал у товара со ступенями: строки удаляются, в событии price_tiers = []', function () {
    $product = makePriceTiersImportProduct();
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $offer = priceTiersOfferXml((string) $product->external_id, '279', null);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toBe([]);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0])->toHaveKey('price_tiers');
    expect(PriceTiersImportRecorder::$payloads[0]['price_tiers'])->toBe([]);
});

test('пустой тег ЦеныОтКоличества равен исчезновению тега', function () {
    $product = makePriceTiersImportProduct();
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $offer = priceTiersOfferXml((string) $product->external_id, '279', []);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toBe([]);
    expect(PriceTiersImportRecorder::$payloads[0]['price_tiers'])->toBe([]);
});

test('товар без ступеней и без тега: сырой payload события цены прежний, ключа price_tiers нет', function () {
    $product = makePriceTiersImportProduct(['Розничная' => 270.0]);

    $offer = priceTiersOfferXml((string) $product->external_id, '279', null);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0])->toBe([
        'price' => 279.0,
        'old_price' => 270.0,
        'price_type' => 'Розничная',
        'currency' => 'RUB',
        'discount_price' => null,
    ]);
    expect(PriceTier::count())->toBe(0);
});

test('товар без ступеней и без тега при той же цене: события нет', function () {
    $product = makePriceTiersImportProduct();

    $offer = priceTiersOfferXml((string) $product->external_id, '279', null);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(0);
});

test('цена изменилась, лесенка прежняя: явного ключа в dispatch нет (лесенку добавит трансформер из БД)', function () {
    $product = makePriceTiersImportProduct(['Розничная' => 270.0]);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $offer = priceTiersOfferXml((string) $product->external_id, '279', [['2', '249']]);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0]['price'])->toBe(279.0);
    expect(PriceTiersImportRecorder::$payloads[0])->not->toHaveKey('price_tiers');
});

test('битая лесенка: предупреждение в лог, сохранённая лесенка не меняется, события нет', function (array $brokenTiers) {
    $channel = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('1c-exchange')->andReturn($channel);

    $product = makePriceTiersImportProduct();
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $offer = priceTiersOfferXml((string) $product->external_id, '279', $brokenTiers);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toBe([[2.0, 249.0]]);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(0);
    $channel->shouldHaveReceived('warning')->once();
})->with([
    'нулевой порог' => [[['0', '249'], ['5', '199']]],
    'отрицательная цена' => [[['2', '-1']]],
    'нечисловая цена' => [[['2', 'abc']]],
    'пустой порог' => [[['', '249']]],
    'повтор порога' => [[['2', '249'], ['2,000', '239']]],
    'нечисловой порог с суффиксом единицы' => [[['5kg', '249']]],
    'цена с пробелом как разделителем разрядов' => [[['2', '1 000']]],
    'цена с неразрывным пробелом как разделителем разрядов' => [[['2', "1\u{00A0}000"]]],
    // P3 (MID-308, фикс-волна): порог/цена вне диапазона колонок price_tiers.
    // min_quantity decimal(10,3): штрихкод, случайно попавший в <Количество>,
    // проходит is_numeric/>0, но не INSERT в MySQL strict mode.
    'штрихкод вместо порога' => [[['4607093991234', '249']]],
    'порог больше верхней границы' => [[['10000000', '249']]],
    'цена больше верхней границы' => [[['2', '10000000000']]],
    'цена в экспоненциальной записи больше верхней границы' => [[['2', '1e12']]],
]);

test('битая лесенка не мешает применить новую цену', function () {
    $product = makePriceTiersImportProduct(['Розничная' => 270.0]);

    $offer = priceTiersOfferXml((string) $product->external_id, '279', [['0', '249']]);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect((float) Price::where('product_id', $product->id)->value('value'))->toBe(279.0);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0]['price'])->toBe(279.0);
    expect(PriceTiersImportRecorder::$payloads[0])->not->toHaveKey('price_tiers');
});

test('немонотонная лесенка от 1С принимается как есть', function () {
    $product = makePriceTiersImportProduct();

    $offer = priceTiersOfferXml((string) $product->external_id, '279', [['2', '249'], ['5', '259']]);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toBe([[2.0, 249.0], [5.0, 259.0]]);
    expect(PriceTiersImportRecorder::$payloads[0]['price_tiers'])->toBe([
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 259.0],
    ]);
});

test('лесенка нерозничного типа сохраняется, но событие не порождает', function () {
    $product = makePriceTiersImportProduct(['Розничная' => 279.0, 'ОПТ' => 250.0]);

    $offer = priceTiersOfferXml((string) $product->external_id, '250', [['10', '230']], 'ОПТ');
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product, 'ОПТ'))->toBe([[10.0, 230.0]]);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(0);
});

test('лесенка закупочного типа в событие не попадает', function () {
    $product = makePriceTiersImportProduct(['Розничная' => 279.0, 'Закупочная' => 150.0]);

    $offer = priceTiersOfferXml((string) $product->external_id, '160', [['100', '140']], 'Закупочная');
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product, 'Закупочная'))->toBe([[100.0, 140.0]]);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0]['cost_price'])->toBe(160.0);
    expect(PriceTiersImportRecorder::$payloads[0])->not->toHaveKey('price_tiers');
});

test('первое появление цены вместе с лесенкой: одно событие с ценой и лесенкой', function () {
    $product = makePriceTiersImportProduct([]);

    $offer = priceTiersOfferXml((string) $product->external_id, '279', [['2', '249']]);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0]['price'])->toBe(279.0);
    expect(PriceTiersImportRecorder::$payloads[0]['old_price'])->toBe(0.0);
    expect(PriceTiersImportRecorder::$payloads[0]['price_tiers'])->toBe([['min_quantity' => 2.0, 'price' => 249.0]]);
});

test('лесенка длиннее 50 ступеней отбрасывается: сохранённая лесенка не меняется, цена блока применяется', function () {
    $product = makePriceTiersImportProduct(['Розничная' => 270.0]);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $tiers = [];
    for ($i = 1; $i <= 51; $i++) {
        $tiers[] = [(string) $i, (string) (1000 - $i)];
    }

    $offer = priceTiersOfferXml((string) $product->external_id, '279', $tiers);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toBe([[2.0, 249.0]]);
    expect((float) Price::where('product_id', $product->id)->value('value'))->toBe(279.0);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0]['price'])->toBe(279.0);
    expect(PriceTiersImportRecorder::$payloads[0])->not->toHaveKey('price_tiers');
});

test('лесенка ровно из 50 ступеней сохраняется целиком', function () {
    $product = makePriceTiersImportProduct();

    $tiers = [];
    for ($i = 1; $i <= 50; $i++) {
        $tiers[] = [(string) $i, (string) (1000 - $i)];
    }

    $offer = priceTiersOfferXml((string) $product->external_id, '279', $tiers);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toHaveCount(50);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0]['price_tiers'])->toHaveCount(50);
});

/**
 * P3 (MID-308, фикс-волна): пороговые значения РОВНО на верхней границе колонок
 * price_tiers (min_quantity decimal(10,3), value decimal(15,2)) — те же
 * MAX_QUANTITY/MAX_PRICE, что в общем классе приёмников PriceTierLadder —
 * принимаются, а не отбрасываются как битые.
 */
test('порог и цена ровно на верхней допустимой границе принимаются', function () {
    $product = makePriceTiersImportProduct();

    $offer = priceTiersOfferXml((string) $product->external_id, '279', [['9999999.999', '9999999999.99']]);
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toBe([[9999999.999, 9999999999.99]]);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersImportRecorder::$payloads[0]['price_tiers'])->toBe([
        ['min_quantity' => 9999999.999, 'price' => 9999999999.99],
    ]);
});

/**
 * P4 (MID-308, фикс-волна): гонка первой вставки лесенки. Когда сохранённых ступеней
 * нет, syncPriceTiers() не делает DELETE и идёт сразу на голый PriceTier::insert():
 * параллельный воркер (numprocs=2), разбирающий тот же товар и тип цены, может
 * вставить ту же ступень между нашим SELECT (видит пусто) и нашим INSERT — второй
 * insert упадёт по уникальному индексу price_tiers_product_type_min_unique.
 *
 * Гонка симулируется через DB::listen на SELECT price_tiers внутри syncPriceTiers():
 * к моменту, когда PHP получает управление обратно от get(), результат уже вычитан
 * из PDO (значит $stored в коде корректно останется «пусто»), а сразу после этого
 * листенер вставляет ту же ступень напрямую в БД — ровно то окно гонки, которое
 * описывает находка.
 */
test('гонка первой вставки: конкурентный INSERT той же ступени между SELECT и нашим INSERT не бросает исключение', function () {
    $product = makePriceTiersImportProduct();

    $raced = false;
    DB::listen(function ($query) use (&$raced, $product) {
        if (! $raced && preg_match('/select \* from ["`]?price_tiers["`]?/i', $query->sql) === 1) {
            $raced = true;

            DB::table('price_tiers')->insert([
                'product_id' => $product->id,
                'price_type' => 'Розничная',
                'min_quantity' => 2,
                'value' => 249,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $offer = priceTiersOfferXml((string) $product->external_id, '279', [['2', '249']]);

    // Прямой вызов без обёртки в closure/toThrow: необработанное исключение здесь
    // само провалит тест (Pest->PHPUnit), а expect()->not->toThrow() в этой версии
    // Pest ненадёжен на классах-предках (Throwable) — не детектирует реальный throw.
    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect($raced)->toBeTrue();
    expect(storedPriceTiers($product))->toBe([[2.0, 249.0]]);
});

/**
 * P5 (MID-308, фикс-волна): опечатка во вложенном теге <ЦенаОтКолличества> (лишняя
 * «л») внутри непустого контейнера <ЦеныОтКоличества>. count($rows) в текущем коде
 * даёт 0 — то же самое, что даёт по-настоящему пустой контейнер, — и лесенка молча
 * стиралась без единого warning. Контейнер, у которого ЕСТЬ дочерние элементы, но
 * среди них нет ни одного <ЦенаОтКоличества>, обязан считаться битой лесенкой:
 * сохранённые ступени не меняются, событие не публикуется, в лог уходит warning.
 */
test('опечатка во вложенном теге контейнера ЦеныОтКоличества: лесенка считается битой, сохранённая не меняется', function () {
    $channel = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('1c-exchange')->andReturn($channel);

    $product = makePriceTiersImportProduct();
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $xml = <<<XML
    <Предложение>
        <Ид>{$product->external_id}</Ид>
        <Цены>
            <Цена>
                <ИдТипаЦены>Розничная</ИдТипаЦены>
                <ЦенаЗаЕдиницу>279</ЦенаЗаЕдиницу>
                <ЦеныОтКоличества>
                    <ЦенаОтКолличества><Количество>5</Количество><ЦенаЗаЕдиницу>199</ЦенаЗаЕдиницу></ЦенаОтКолличества>
                </ЦеныОтКоличества>
                <Валюта>RUB</Валюта>
            </Цена>
        </Цены>
    </Предложение>
    XML;
    $offer = new SimpleXMLElement($xml);

    priceTiersImporter()->exposeImportPrices($product, $offer);

    expect(storedPriceTiers($product))->toBe([[2.0, 249.0]]);
    expect(PriceTiersImportRecorder::$payloads)->toHaveCount(0);
    $channel->shouldHaveReceived('warning')->once();
});
