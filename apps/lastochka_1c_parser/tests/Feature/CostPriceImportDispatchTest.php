<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\Product;
use App\Services\Exchange1C\OffersImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Ключевая семантика Task 6 (закупочная цена в price_updated, см. adr-task-6-report.md
 * и tests/Feature/CostPriceEventTest.php — тот файл покрывает только уровень
 * ProductEventTransformer, НЕ OffersImporter::importPrices):
 *
 * При изменении ЗАКУПОЧНОГО типа цены поле `price` в событии ВСЕГДА розничное —
 * читается из БД (OffersImporter::resolveRetailPrice), новая закупочная уезжает
 * отдельным полем `cost_price`. Если розницы ещё нет — событие price_updated не
 * шлётся вовсе (cost_price доедет полным product.updated после каталога).
 * Для розничного типа поведение НЕ изменилось: price = новая розница; explicit
 * cost_price в payload OffersImporter не передаёт — этот фолбэк из БД делает
 * ProductEventTransformer::buildPriceUpdatedPayload() ниже по цепочке
 * (CustomEventPublisher::publish → toCustomEvent), уже покрыт CostPriceEventTest.php.
 *
 * Подход к фикстурам offer-XML и alias-моку CustomEventPublisher — как в
 * tests/Feature/PriceDiscountImportTest.php (реальный вызов protected importPrices
 * через анонимную обёртку-наследника).
 */

/**
 * Recorder как отдельный статический класс, а не замыкание в beforeEach — alias-мок
 * Mockery::mock('alias:'.CustomEventPublisher::class) падает с "class already exists",
 * если PHP уже когда-либо успел автозагрузить реальный класс в этом процессе; вынос
 * состояния в отдельный класс здесь ни при чём напрямую, но повторяем паттерн
 * PriceDiscountImportTest.php для единообразия и на случай смешанного прогона сьютом.
 */
class CostPriceImportDispatchRecorder
{
    /** @var array<int, array<string, mixed>> */
    public static array $calls = [];
}

beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'lastochka');
    config()->set('rabbitmq-events.source', '1c');
    config()->set('exchange1c.retail_price_types', ['Розничная']);
    config()->set('exchange1c.cost_price_types', ['Закупочная']);

    CostPriceImportDispatchRecorder::$calls = [];

    $mock = Mockery::mock('alias:'.CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) {
            CostPriceImportDispatchRecorder::$calls[] = $payload;

            return 'test-event-id';
        });
});

function makeCostDispatchProduct(): Product
{
    return Product::factory()->withoutCategory()->create(['shop_id' => 1]);
}

function costDispatchOfferXml(string $productId, string $priceTypeId, float $price, string $currency = 'RUB'): SimpleXMLElement
{
    $xml = <<<XML
    <Предложение>
        <Ид>{$productId}</Ид>
        <Цены>
            <Цена>
                <ИдТипаЦены>{$priceTypeId}</ИдТипаЦены>
                <ЦенаЗаЕдиницу>{$price}</ЦенаЗаЕдиницу>
                <Валюта>{$currency}</Валюта>
            </Цена>
        </Цены>
    </Предложение>
    XML;

    return new SimpleXMLElement($xml);
}

function exposeImportPricesForCostDispatch(): OffersImporter
{
    return new class extends OffersImporter
    {
        public function exposeImportPrices(Product $product, SimpleXMLElement $offer): void
        {
            $this->importPrices($product, $offer);
        }
    };
}

test('cost-тип меняется при существующей рознице: price_updated с розничной ценой в price и новой закупочной в cost_price', function () {
    $product = makeCostDispatchProduct();

    Price::create([
        'product_id' => $product->id,
        'price_type' => 'Розничная',
        'value' => 150.00,
        'currency' => 'RUB',
    ]);
    Price::create([
        'product_id' => $product->id,
        'price_type' => 'Закупочная',
        'value' => 70.00,
        'currency' => 'RUB',
    ]);

    $offer = costDispatchOfferXml((string) $product->external_id, 'Закупочная', 85.50);
    exposeImportPricesForCostDispatch()->exposeImportPrices($product, $offer);

    expect(CostPriceImportDispatchRecorder::$calls)->toHaveCount(1);
    $payload = CostPriceImportDispatchRecorder::$calls[0];

    expect($payload['price'])->toBe(150.00);
    expect($payload['old_price'])->toBe(150.00);
    expect($payload['price_type'])->toBe('Розничная');
    expect($payload['cost_price'])->toBe(85.50);

    // Импорт цены в БД не зависит от диспатча: закупочная обновлена, розница не тронута.
    expect((float) Price::where('product_id', $product->id)->where('price_type', 'Закупочная')->value('value'))->toBe(85.50);
    expect((float) Price::where('product_id', $product->id)->where('price_type', 'Розничная')->value('value'))->toBe(150.00);
});

test('cost-тип меняется, розницы в БД нет: price_updated не диспатчится', function () {
    $product = makeCostDispatchProduct();

    $offer = costDispatchOfferXml((string) $product->external_id, 'Закупочная', 90.00);
    exposeImportPricesForCostDispatch()->exposeImportPrices($product, $offer);

    expect(CostPriceImportDispatchRecorder::$calls)->toHaveCount(0);

    // Цена в БД всё равно сохраняется — Price::updateOrCreate не зависит от диспатча события.
    expect((float) Price::where('product_id', $product->id)->where('price_type', 'Закупочная')->value('value'))->toBe(90.00);
});

test('retail-тип меняется: поведение прежнее (price = новая розница), cost_price оставлен фолбэку транформера', function () {
    $product = makeCostDispatchProduct();

    Price::create([
        'product_id' => $product->id,
        'price_type' => 'Розничная',
        'value' => 100.00,
        'currency' => 'RUB',
    ]);
    // Закупочная цена присутствует в БД — её фолбэк из БД делает
    // ProductEventTransformer::buildPriceUpdatedPayload (см. CostPriceEventTest.php),
    // а не OffersImporter напрямую. Здесь мы проверяем, что OffersImporter не
    // подменяет/не блокирует этот фолбэк собственным явным cost_price.
    Price::create([
        'product_id' => $product->id,
        'price_type' => 'Закупочная',
        'value' => 60.00,
        'currency' => 'RUB',
    ]);

    $offer = costDispatchOfferXml((string) $product->external_id, 'Розничная', 120.00);
    exposeImportPricesForCostDispatch()->exposeImportPrices($product, $offer);

    expect(CostPriceImportDispatchRecorder::$calls)->toHaveCount(1);
    $payload = CostPriceImportDispatchRecorder::$calls[0];

    expect($payload['price'])->toBe(120.00);
    expect($payload['old_price'])->toBe(100.00);
    expect($payload['price_type'])->toBe('Розничная');
    // OffersImporter НЕ передаёт cost_price явно для retail-ветки — ключ отсутствует
    // в сыром payload dispatch(); фолбэк из БД добавляет транформер ниже по цепочке.
    expect($payload)->not->toHaveKey('cost_price');
});
