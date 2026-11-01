<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Services\Exchange1C\CatalogImporter;
use App\Services\Exchange1C\OffersImporter;
use App\Services\Exchange1C\ProductWhitelist;
use App\Services\Exchange1C\RestsImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Фикс deadlock импорта 1С (GlitchTip 1C_PARSER-2), проверка на реальной БД.
 *
 * Слой 2 — из Offers/RestsImporter убран избыточный `$product->touch()`: он не
 * публиковал событий ('updated' для products отключён + ProductSyncPublishCondition
 * отсекает не-значимые поля), но делал лишний UPDATE products и втягивал offers/rests
 * в deadlock с CatalogImporter по таблице products. Здесь убеждаемся, что импорт
 * остатка больше НЕ трогает products.updated_at.
 *
 * Слой 3 — CatalogImporter дробит товары на батчи; проверяем, что весь каталог
 * импортируется (батчи 2+2+1) и категория привязывается (значит категории
 * закоммичены до товаров).
 */
beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    // Явные и observer-публикации перехватываем, чтобы тест не зависел от RabbitMQ.
    $mock = Mockery::mock('alias:'.CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')->andReturn('test-event-id')->byDefault();
});

afterEach(function () {
    Mockery::close();
});

function offersQuantityXml(string $externalId, float $quantity): string
{
    $xml = <<<XML
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

    $tmp = tempnam(sys_get_temp_dir(), 'notouch_offers_').'.xml';
    file_put_contents($tmp, $xml);

    return $tmp;
}

function restsQuantityXml(string $externalId, float $quantity): string
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <ПакетПредложений>
        <Предложения>
            <Предложение>
                <Ид>{$externalId}</Ид>
                <Остатки>
                    <Остаток>
                        <Количество>{$quantity}</Количество>
                    </Остаток>
                </Остатки>
            </Предложение>
        </Предложения>
    </ПакетПредложений>
</КоммерческаяИнформация>
XML;

    $tmp = tempnam(sys_get_temp_dir(), 'notouch_rests_').'.xml';
    file_put_contents($tmp, $xml);

    return $tmp;
}

function catalogWithCategoryXml(string $categoryExternalId, int $productsCount): string
{
    $items = '';
    for ($i = 1; $i <= $productsCount; $i++) {
        $items .= "<Товар><Ид>cat-prod-{$i}</Ид><Наименование>Товар {$i}</Наименование>"
            ."<Группы><Ид>{$categoryExternalId}</Ид></Группы></Товар>\n";
    }

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Классификатор>
        <Группы>
            <Группа><Ид>{$categoryExternalId}</Ид><Наименование>Категория</Наименование></Группа>
        </Группы>
    </Классификатор>
    <Каталог>
        <Товары>{$items}</Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;

    $tmp = tempnam(sys_get_temp_dir(), 'notouch_catalog_').'.xml';
    file_put_contents($tmp, $xml);

    return $tmp;
}

test('OffersImporter no longer touches product.updated_at (deadlock fix layer 2)', function () {
    $product = makeProduct(['external_id' => 'guid-touch-off', 'shop_id' => 1]);

    // Фиксируем updated_at в далёкое прошлое в обход Eloquent-таймстампов.
    DB::table('products')->where('id', $product->id)->update(['updated_at' => Carbon::create(2020, 1, 1, 0, 0, 0)]);

    $xmlPath = offersQuantityXml('guid-touch-off', 7);
    (new OffersImporter(1))->importChunk($xmlPath, 0, 10);

    $fresh = Product::find($product->id);

    // touch() убран → updated_at не изменился.
    expect($fresh->updated_at->format('Y-m-d H:i:s'))->toBe('2020-01-01 00:00:00');
    // Санити: остаток реально импортирован (путь дошёл до importStocks).
    expect((float) Stock::where('product_id', $product->id)->sum('quantity'))->toBe(7.0);

    @unlink($xmlPath);
});

test('RestsImporter no longer touches product.updated_at (deadlock fix layer 2)', function () {
    $product = makeProduct(['external_id' => 'guid-touch-rest', 'shop_id' => 1]);

    DB::table('products')->where('id', $product->id)->update(['updated_at' => Carbon::create(2020, 1, 1, 0, 0, 0)]);

    $xmlPath = restsQuantityXml('guid-touch-rest', 3);
    (new RestsImporter(1))->import($xmlPath);

    $fresh = Product::find($product->id);

    expect($fresh->updated_at->format('Y-m-d H:i:s'))->toBe('2020-01-01 00:00:00');
    expect((float) Stock::where('product_id', $product->id)->sum('quantity'))->toBe(3.0);

    @unlink($xmlPath);
});

test('CatalogImporter imports the whole catalog across batches and links category (layer 3)', function () {
    // batch_size=2 при 5 товарах → батчи 2+2+1, каждый в своей транзакции.
    config()->set('exchange1c.batch_size', 2);

    $xmlPath = catalogWithCategoryXml('cat-root', 5);

    $result = (new CatalogImporter(new ProductWhitelist(null), 1))->import($xmlPath);

    // Все товары импортированы несмотря на дробление.
    expect($result['products'])->toBe(5);
    expect(Product::where('shop_id', 1)->count())->toBe(5);

    // Категория закоммичена ДО батчей товаров — иначе category_id был бы null.
    $category = Category::where('external_id', 'cat-root')->where('shop_id', 1)->first();
    expect($category)->not->toBeNull();
    expect(Product::where('shop_id', 1)->whereNotNull('category_id')->count())->toBe(5);
    expect(Product::where('shop_id', 1)->pluck('category_id')->unique()->all())->toBe([$category->id]);

    @unlink($xmlPath);
});
