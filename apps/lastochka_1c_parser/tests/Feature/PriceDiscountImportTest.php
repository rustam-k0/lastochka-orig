<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\Product;
use App\Services\Exchange1C\OffersImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Акционная цена — кастомный реквизит <ЦенаЗаЕдиницуСоСкидкой> внутри того же
 * блока <Цена>, рядом с <ЦенаЗаЕдиницу>. Не отдельный тип цены: не нужен
 * маппинг GUID/имён для распознавания скидки, снятие акции = 1С просто
 * перестаёт слать тег в следующей выгрузке.
 */
/**
 * Хранилище перехваченных payload'ов price_updated. Alias-мок CustomEventPublisher
 * заводится один раз на файл в beforeEach — если оставить настоящий класс
 * невтронутым хотя бы в одном тесте, PHP один раз подгрузит его в процессе,
 * и Mockery::mock('alias:...') во всех последующих тестах файла упадёт с
 * "class already exists" (алиас можно объявить, пока класс ещё не загружен).
 */
class PriceDiscountEventRecorder
{
    /** @var array<int, array<string, mixed>> */
    public static array $payloads = [];
}

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    PriceDiscountEventRecorder::$payloads = [];

    $mock = Mockery::mock('alias:'.CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) {
            PriceDiscountEventRecorder::$payloads[] = $payload;

            return 'test-event-id';
        });
});

function offerXmlWithDiscount(string $productId, float $price, ?float $discount, string $priceTypeId = 'guid-price-type'): SimpleXMLElement
{
    $discountTag = $discount !== null ? "<ЦенаЗаЕдиницуСоСкидкой>{$discount}</ЦенаЗаЕдиницуСоСкидкой>" : '';

    $xml = <<<XML
    <Предложение>
        <Ид>{$productId}</Ид>
        <Цены>
            <Цена>
                <ИдТипаЦены>{$priceTypeId}</ИдТипаЦены>
                <ЦенаЗаЕдиницу>{$price}</ЦенаЗаЕдиницу>
                {$discountTag}
                <Валюта>RUB</Валюта>
            </Цена>
        </Цены>
    </Предложение>
    XML;

    return new SimpleXMLElement($xml);
}

function importerExposingImportPrices(): OffersImporter
{
    return new class extends OffersImporter
    {
        public function exposeImportPrices(Product $product, SimpleXMLElement $offer): void
        {
            $this->importPrices($product, $offer);
        }
    };
}

test('importPrices stores discount_value from ЦенаЗаЕдиницуСоСкидкой', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    $offer = offerXmlWithDiscount((string) $product->external_id, 100.0, 79.0);

    importerExposingImportPrices()->exposeImportPrices($product, $offer);

    $price = Price::where('product_id', $product->id)->first();
    expect((float) $price->value)->toBe(100.0);
    expect((float) $price->discount_value)->toBe(79.0);
});

test('importPrices ignores discount when it is not lower than base price', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    $offer = offerXmlWithDiscount((string) $product->external_id, 100.0, 100.0);

    importerExposingImportPrices()->exposeImportPrices($product, $offer);

    $price = Price::where('product_id', $product->id)->first();
    expect($price->discount_value)->toBeNull();
});

test('importPrices ignores non-positive discount', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    $offer = offerXmlWithDiscount((string) $product->external_id, 100.0, 0.0);

    importerExposingImportPrices()->exposeImportPrices($product, $offer);

    $price = Price::where('product_id', $product->id)->first();
    expect($price->discount_value)->toBeNull();
});

test('importPrices clears discount_value when 1C stops sending the discount tag', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create([
        'product_id' => $product->id,
        'price_type' => 'guid-price-type',
        'value' => 100.0,
        'discount_value' => 79.0,
        'currency' => 'RUB',
    ]);

    $offer = offerXmlWithDiscount((string) $product->external_id, 100.0, null);

    importerExposingImportPrices()->exposeImportPrices($product, $offer);

    $price = Price::where('product_id', $product->id)->first();
    expect($price->discount_value)->toBeNull();
});

test('importPrices dispatches price_updated with discount_price when discount changes but base price does not', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create([
        'product_id' => $product->id,
        'price_type' => 'guid-price-type',
        'value' => 100.0,
        'discount_value' => null,
        'currency' => 'RUB',
    ]);

    $offer = offerXmlWithDiscount((string) $product->external_id, 100.0, 79.0);
    importerExposingImportPrices()->exposeImportPrices($product, $offer);

    expect(PriceDiscountEventRecorder::$payloads)->toHaveCount(1);
    expect(PriceDiscountEventRecorder::$payloads[0]['price'])->toBe(100.0);
    expect(PriceDiscountEventRecorder::$payloads[0]['discount_price'])->toBe(79.0);
});

test('importPrices does not dispatch price_updated when neither price nor discount changed', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create([
        'product_id' => $product->id,
        'price_type' => 'guid-price-type',
        'value' => 100.0,
        'discount_value' => 79.0,
        'currency' => 'RUB',
    ]);

    $offer = offerXmlWithDiscount((string) $product->external_id, 100.0, 79.0);
    importerExposingImportPrices()->exposeImportPrices($product, $offer);

    expect(PriceDiscountEventRecorder::$payloads)->toHaveCount(0);
});
