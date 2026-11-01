<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use App\Services\Exchange1C\OffersImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * MID-308 (P8, фикс-волна). Два сценария, которые существующие тесты не покрывали:
 *
 * 1. Страж самого опасного сценария для живого обмена: предложение БЕЗ секции
 *    <Цены> (облегчённая выгрузка остатков — 1С шлёт цены и остатки отдельными
 *    файлами) не должно стирать сохранённую лесенку и не должно публиковать по
 *    ней событий. Поведение уже верное (ранний выход importPrices()), тест
 *    закрепляет его от будущих правок.
 *
 * 2. Сквозной тест через ПУБЛИЧНЫЙ вход импортёра (import(), как его вызывает
 *    ImportOffersJob): типы цен объявлены в <ТипыЦен> с GUID в <Ид> и
 *    человекочитаемым <Наименование>, блоки <Цена> ссылаются на GUID в
 *    <ИдТипаЦены>. Существующие тесты (PriceTiersImportTest.php) дёргают
 *    exposeImportPrices() напрямую с price_type = человекочитаемое имя и не
 *    проходят через разбор <ТипыЦен> — то есть никогда не проверяли, что
 *    price_tiers сохраняется и ищется под ОДНИМ и тем же ключом, когда 1С
 *    реально шлёт GUID.
 */
beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
    config()->set('exchange1c.retail_price_types', ['Розничная']);
    config()->set('exchange1c.cost_price_types', []);

    PriceTiersEndToEndRecorder::$payloads = [];

    $mock = Mockery::mock('alias:'.CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) {
            PriceTiersEndToEndRecorder::$payloads[] = $payload;

            return 'test-event-id';
        });

    $this->tempDir = sys_get_temp_dir().'/price_tiers_e2e_'.uniqid();
    mkdir($this->tempDir, 0777, true);
});

afterEach(function () {
    Mockery::close();

    foreach (glob($this->tempDir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->tempDir);
});

class PriceTiersEndToEndRecorder
{
    /** @var array<int, array<string, mixed>> */
    public static array $payloads = [];
}

function writeE2eOffersXml(string $dir, string $xml): string
{
    $path = $dir.'/offers___'.uniqid().'.xml';
    file_put_contents($path, $xml);

    return $path;
}

/**
 * Полный пакет предложений с секцией <ТипыЦен> (GUID → человекочитаемое имя)
 * и блоком <Цена>, который ссылается на GUID в <ИдТипаЦены> — как реально
 * шлёт 1С, а не «имя цены прямо в ИдТипаЦены», как в упрощённых юнит-тестах.
 *
 * @param  array<int, array{0: string, 1: string}>|null  $tiers  [[Количество, ЦенаЗаЕдиницу], ...]; null = тега нет
 */
function e2eOffersWithGuidPriceType(string $externalId, string $price, ?array $tiers, string $guid, string $typeName): string
{
    $tiersTag = '';

    if ($tiers !== null) {
        $rows = '';
        foreach ($tiers as [$quantity, $tierPrice]) {
            $rows .= "<ЦенаОтКоличества><Количество>{$quantity}</Количество><ЦенаЗаЕдиницу>{$tierPrice}</ЦенаЗаЕдиницу></ЦенаОтКоличества>";
        }
        $tiersTag = "<ЦеныОтКоличества>{$rows}</ЦеныОтКоличества>";
    }

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <КоммерческаяИнформация>
        <ПакетПредложений>
            <ТипыЦен>
                <ТипЦены>
                    <Ид>{$guid}</Ид>
                    <Наименование>{$typeName}</Наименование>
                    <Валюта>RUB</Валюта>
                </ТипЦены>
            </ТипыЦен>
            <Предложения>
                <Предложение>
                    <Ид>{$externalId}</Ид>
                    <Цены>
                        <Цена>
                            <ИдТипаЦены>{$guid}</ИдТипаЦены>
                            <ЦенаЗаЕдиницу>{$price}</ЦенаЗаЕдиницу>
                            {$tiersTag}
                            <Валюта>RUB</Валюта>
                        </Цена>
                    </Цены>
                </Предложение>
            </Предложения>
        </ПакетПредложений>
    </КоммерческаяИнформация>
    XML;
}

/**
 * Облегчённая выгрузка остатков: секции <Цены> нет вовсе, только количество —
 * ровно формат, которым 1С шлёт остатки отдельно от цен.
 */
function e2eStockOnlyOffersXml(string $externalId, string $quantity): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <КоммерческаяИнформация>
        <ПакетПредложений>
            <Предложения>
                <Предложение>
                    <Ид>{$externalId}</Ид>
                    <Количество>{$quantity}</Количество>
                </Предложение>
            </Предложения>
        </ПакетПредложений>
    </КоммерческаяИнформация>
    XML;
}

test('облегчённая выгрузка остатков (без <Цены>) не стирает сохранённую лесенку и не публикует по ней событий', function () {
    $product = Product::factory()->withoutCategory()->create(['external_id' => 'prod-e2e-nostock', 'shop_id' => 1]);
    Price::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 5, 'value' => 199]);

    PriceTiersEndToEndRecorder::$payloads = [];

    (new OffersImporter(1))->import(writeE2eOffersXml($this->tempDir, e2eStockOnlyOffersXml('prod-e2e-nostock', '42')));

    $storedTiers = PriceTier::where('product_id', $product->id)->orderBy('min_quantity')->get();
    expect($storedTiers)->toHaveCount(2);
    expect((float) $storedTiers[0]->min_quantity)->toBe(2.0);
    expect((float) $storedTiers[1]->min_quantity)->toBe(5.0);

    // Цена не тронута облегчённой выгрузкой остатков.
    expect((float) Price::where('product_id', $product->id)->value('value'))->toBe(279.0);

    // Событие могло уйти по остатку (stock_updated), но НЕ по цене/лесенке.
    $priceUpdatedPayloads = array_filter(
        PriceTiersEndToEndRecorder::$payloads,
        fn (array $payload): bool => array_key_exists('price_type', $payload)
    );
    expect($priceUpdatedPayloads)->toBe([]);
});

test('сквозной сценарий через import(): GUID типа цены, лесенка сохраняется под именем, идемпотентность, очистка', function () {
    $guid = 'a1b2c3d4-1111-1111-1111-111111111111';
    $externalId = 'prod-e2e-guid';

    $product = Product::factory()->withoutCategory()->create(['external_id' => $externalId, 'shop_id' => 1]);

    // Шаг 1: первая выгрузка с лесенкой под GUID типа цены.
    (new OffersImporter(1))->import(writeE2eOffersXml(
        $this->tempDir,
        e2eOffersWithGuidPriceType($externalId, '279.00', [['2', '249.00'], ['5', '199.00']], $guid, 'Розничная')
    ));

    $storedTiers = PriceTier::where('product_id', $product->id)->orderBy('min_quantity')->get();
    expect($storedTiers)->toHaveCount(2);
    // Ключевая проверка P8: лесенка сохранена под ЧЕЛОВЕКОЧИТАЕМЫМ именем типа
    // цены (как его резолвит <ТипыЦен>), а не под сырым GUID из <ИдТипаЦены>.
    expect($storedTiers->pluck('price_type')->unique()->all())->toBe(['Розничная']);
    expect((float) $storedTiers[0]->min_quantity)->toBe(2.0);
    expect((float) $storedTiers[0]->value)->toBe(249.0);
    expect((float) $storedTiers[1]->min_quantity)->toBe(5.0);
    expect((float) $storedTiers[1]->value)->toBe(199.0);

    expect(Price::where('product_id', $product->id)->value('price_type'))->toBe('Розничная');

    expect(PriceTiersEndToEndRecorder::$payloads)->toHaveCount(1);
    $firstPayload = PriceTiersEndToEndRecorder::$payloads[0];
    expect($firstPayload['price_type'])->toBe('Розничная');
    // Ключевая проверка P8: событие несёт лесенку, а не пустой ключ — то есть
    // isRetailPriceType()/resolveRetailPriceType() узнали в GUID-типе розничный
    // тип, сохранённый под тем же именем, что и Price-строка.
    expect($firstPayload)->toHaveKey('price_tiers');
    expect($firstPayload['price_tiers'])->toBe([
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 199.0],
    ]);

    // Шаг 2: повторная ТА ЖЕ выгрузка — ничего не изменилось, событий нет.
    PriceTiersEndToEndRecorder::$payloads = [];

    (new OffersImporter(1))->import(writeE2eOffersXml(
        $this->tempDir,
        e2eOffersWithGuidPriceType($externalId, '279.00', [['2', '249.00'], ['5', '199.00']], $guid, 'Розничная')
    ));

    expect(PriceTiersEndToEndRecorder::$payloads)->toBe([]);
    expect(PriceTier::where('product_id', $product->id)->count())->toBe(2);

    // Шаг 3: выгрузка без тега <ЦеныОтКоличества> — лесенка очищена, событие
    // с price_tiers: [].
    PriceTiersEndToEndRecorder::$payloads = [];

    (new OffersImporter(1))->import(writeE2eOffersXml(
        $this->tempDir,
        e2eOffersWithGuidPriceType($externalId, '279.00', null, $guid, 'Розничная')
    ));

    expect(PriceTier::where('product_id', $product->id)->count())->toBe(0);
    expect(PriceTiersEndToEndRecorder::$payloads)->toHaveCount(1);
    expect(PriceTiersEndToEndRecorder::$payloads[0])->toHaveKey('price_tiers');
    expect(PriceTiersEndToEndRecorder::$payloads[0]['price_tiers'])->toBe([]);
});
