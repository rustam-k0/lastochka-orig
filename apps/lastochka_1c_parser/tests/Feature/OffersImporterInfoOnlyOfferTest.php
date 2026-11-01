<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\Product;
use App\Models\Stock;
use App\Services\Exchange1C\OffersImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * 1С «Ласточки» шлёт в одной сессии offers___*.xml БЕЗ цен и остатков
 * (только Ид/НомерВерсии/ПометкаУдаления/Наименование/Штрихкод) — данные
 * приходят отдельными prices___*.xml и rests___*.xml.
 *
 * До фикса importOffer для каждого такого «пустого» предложения делал
 * SELECT товара, 2× SUM остатков и touch() (UPDATE products) — ~13k пустых
 * UPDATE за полную выгрузку и дедлоки (SQLSTATE 40001) с параллельными
 * prices/rests-джобами по тем же товарам.
 */
beforeEach(function () {
    $this->tempXmlPath = tempnam(sys_get_temp_dir(), 'offers_info_only_') . '.xml';

    // Импорт цены шлёт price_updated через статический CustomEventPublisher::dispatch.
    // Без alias-мока этот файл автозагружал настоящий класс, и если он шёл в сьюте раньше
    // файлов с Mockery::mock('alias:...') (PriceUpdatedEventTest, PriceDiscountImportTest и др.),
    // все их тесты падали с "class already exists".
    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')->andReturn('test-event-id');
});

afterEach(function () {
    if (file_exists($this->tempXmlPath)) {
        unlink($this->tempXmlPath);
    }
});

function infoOnlyOffersXml(): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <ПакетПредложений>
        <Предложения>
            <Предложение>
                <Ид>product-io-1</Ид>
                <НомерВерсии>AAAAAAM10CI=</НомерВерсии>
                <ПометкаУдаления>false</ПометкаУдаления>
                <Наименование>Хек тушка 200-300</Наименование>
                <Штрихкод>0000000002444</Штрихкод>
            </Предложение>
        </Предложения>
    </ПакетПредложений>
</КоммерческаяИнформация>
XML;
}

test('предложение без цен и остатков не трогает товар (нет touch, цен, остатков)', function () {
    $product = Product::create([
        'external_id' => 'product-io-1',
        'shop_id' => 1,
        'name' => 'Хек тушка 200-300',
        'synced_at' => now(),
    ]);

    DB::table('products')->where('id', $product->id)->update([
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    file_put_contents($this->tempXmlPath, infoOnlyOffersXml());

    $result = (new OffersImporter(1))->import($this->tempXmlPath);

    // Пустое предложение не считается «товар не найден» — retry не нужен.
    expect($result['skipped'])->toBe(0);
    expect($result['prices'])->toBe(0);
    expect($result['stocks'])->toBe(0);

    $fresh = Product::findOrFail($product->id);
    expect($fresh->updated_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00');
    expect(Price::count())->toBe(0);
    expect(Stock::count())->toBe(0);
});

test('предложение с ценой обрабатывается как раньше', function () {
    $product = Product::create([
        'external_id' => 'product-io-2',
        'shop_id' => 1,
        'name' => 'Товар с ценой',
        'synced_at' => now(),
    ]);

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <ПакетПредложений>
        <Предложения>
            <Предложение>
                <Ид>product-io-2</Ид>
                <Цены>
                    <Цена>
                        <ИдТипаЦены>price-type-1</ИдТипаЦены>
                        <ЦенаЗаЕдиницу>450</ЦенаЗаЕдиницу>
                        <Валюта>RUB</Валюта>
                    </Цена>
                </Цены>
            </Предложение>
        </Предложения>
    </ПакетПредложений>
</КоммерческаяИнформация>
XML;
    file_put_contents($this->tempXmlPath, $xml);

    (new OffersImporter(1))->import($this->tempXmlPath);

    expect(Price::where('product_id', $product->id)->count())->toBe(1);
    expect((float) Price::where('product_id', $product->id)->value('value'))->toBe(450.0);
});
