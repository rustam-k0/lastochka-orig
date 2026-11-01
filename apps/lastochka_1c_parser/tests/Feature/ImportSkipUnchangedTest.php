<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\IntegrationConfig;
use App\Models\Product;
use App\Models\Stock;
use App\RabbitMQ\Conditions\CategoriesSyncPublishCondition;
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
 * Регресс на холостую перезапись каталога (GlitchTip ADMIN_BACK-2Q, 2026-09-07).
 *
 * 1С Ласточки шлёт полный каталог каждые ~3.5 минуты. Импортёры писали
 * `synced_at => now()` в данные для fill()/updateOrCreate, из-за чего модель
 * всегда была «грязной», и Eloquent делал UPDATE каждой строки products/stocks/
 * categories даже без содержательных изменений: ~130k UPDATE за 20 минут,
 * ~9.5G binlog в сутки, миллион строк «Event skipped by PublishCondition»,
 * 130k пустых category_1c.updated в день. Диск прода дважды забивался до 100%.
 *
 * Новая семантика: `synced_at` = «последний раз реально обновили из 1С».
 * Повторный импорт без изменений не должен трогать БД вообще.
 */
beforeEach(function () {
    config(['exchange1c.whitelist_enabled' => false]);
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'lastochka');
    config()->set('rabbitmq-events.source', '1c');

    $mock = Mockery::mock('alias:' . CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')->andReturn('test-event-id');

    $this->tempDir = sys_get_temp_dir() . '/skip_unchanged_' . uniqid();
    mkdir($this->tempDir, 0777, true);
});

afterEach(function () {
    Mockery::close();
    Carbon::setTestNow();

    foreach (glob($this->tempDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->tempDir);
});

/**
 * Считает UPDATE-запросы по таблице за время выполнения $fn.
 */
function updateQueriesFor(string $table, callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $fn();

    $count = collect(DB::getQueryLog())
        ->filter(fn (array $q) => preg_match('/^\s*update\s+["`]?' . preg_quote($table, '/') . '["`]?\s/i', $q['query']) === 1)
        ->count();

    DB::disableQueryLog();

    return $count;
}

function writeTempXml(string $dir, string $prefix, string $xml): string
{
    $path = $dir . '/' . $prefix . '___' . uniqid() . '.xml';
    file_put_contents($path, $xml);

    return $path;
}

function catalogXml(string $groupName = 'Молочка', string $productName = 'Молоко 3.2%'): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Классификатор>
        <Группы>
            <Группа>
                <Ид>grp-su-1</Ид>
                <Наименование>{$groupName}</Наименование>
            </Группа>
        </Группы>
    </Классификатор>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>prod-su-1</Ид>
                <Наименование>{$productName}</Наименование>
                <Артикул>10001</Артикул>
                <Штрихкод>4600000000017</Штрихкод>
                <БазоваяЕдиница Код="796">шт</БазоваяЕдиница>
                <Группы><Ид>grp-su-1</Ид></Группы>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
}

function restsXml(string $quantity): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация xmlns="urn:1C.ru:commerceml_2" ВерсияСхемы="2.10">
    <ПакетПредложений СодержитТолькоИзменения="true">
        <Предложения>
            <Предложение>
                <Ид>prod-su-1</Ид>
                <Остатки><Остаток><Количество>{$quantity}</Количество></Остаток></Остатки>
            </Предложение>
        </Предложения>
    </ПакетПредложений>
</КоммерческаяИнформация>
XML;
}

function offersXml(string $quantity, string $warehouseQuantity): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <ПакетПредложений>
        <Склады>
            <Склад><Ид>wh-su-1</Ид><Наименование>Основной</Наименование></Склад>
        </Склады>
        <Предложения>
            <Предложение>
                <Ид>prod-su-1</Ид>
                <Количество>{$quantity}</Количество>
                <Склад ИдСклада="wh-su-1" КоличествоНаСкладе="{$warehouseQuantity}"/>
            </Предложение>
        </Предложения>
    </ПакетПредложений>
</КоммерческаяИнформация>
XML;
}

// ---------------------------------------------------------------------------
// CatalogImporter: товары
// ---------------------------------------------------------------------------

test('повторный импорт каталога без изменений не делает UPDATE products и не трогает synced_at', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    (new CatalogImporter(new ProductWhitelist(), 1))->import(writeTempXml($this->tempDir, 'import', catalogXml()));

    $before = Product::where('external_id', 'prod-su-1')->firstOrFail();
    expect($before->synced_at?->toDateTimeString())->toBe('2026-09-07 10:00:00');

    $updated = false;
    Product::updated(function () use (&$updated) {
        $updated = true;
    });

    Carbon::setTestNow('2026-09-07 10:03:30');
    $updates = updateQueriesFor('products', function () {
        (new CatalogImporter(new ProductWhitelist(), 1))->import(writeTempXml($this->tempDir, 'import', catalogXml()));
    });

    $after = Product::where('external_id', 'prod-su-1')->firstOrFail();

    expect($updates)->toBe(0);
    expect($updated)->toBeFalse();
    expect($after->synced_at?->toDateTimeString())->toBe('2026-09-07 10:00:00');
    expect($after->updated_at?->toDateTimeString())->toBe('2026-09-07 10:00:00');
});

test('изменение наименования товара по-прежнему сохраняется и обновляет synced_at', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    (new CatalogImporter(new ProductWhitelist(), 1))->import(writeTempXml($this->tempDir, 'import', catalogXml()));

    Carbon::setTestNow('2026-09-07 10:03:30');
    $updates = updateQueriesFor('products', function () {
        (new CatalogImporter(new ProductWhitelist(), 1))
            ->import(writeTempXml($this->tempDir, 'import', catalogXml(productName: 'Молоко 2.5%')));
    });

    $after = Product::where('external_id', 'prod-su-1')->firstOrFail();

    expect($updates)->toBe(1);
    expect($after->name)->toBe('Молоко 2.5%');
    expect($after->synced_at?->toDateTimeString())->toBe('2026-09-07 10:03:30');
});

test('восстановление мягко удалённого товара сохраняется и обновляет synced_at', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    (new CatalogImporter(new ProductWhitelist(), 1))->import(writeTempXml($this->tempDir, 'import', catalogXml()));
    Product::where('external_id', 'prod-su-1')->firstOrFail()->delete();

    Carbon::setTestNow('2026-09-07 10:03:30');
    (new CatalogImporter(new ProductWhitelist(), 1))->import(writeTempXml($this->tempDir, 'import', catalogXml()));

    $after = Product::where('external_id', 'prod-su-1')->firstOrFail();

    expect($after->trashed())->toBeFalse();
    expect($after->synced_at?->toDateTimeString())->toBe('2026-09-07 10:03:30');
});

// ---------------------------------------------------------------------------
// CatalogImporter: категории
// ---------------------------------------------------------------------------

test('повторный импорт классификатора без изменений не делает UPDATE categories и не стреляет updated', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    (new CatalogImporter(new ProductWhitelist(), 1))->import(writeTempXml($this->tempDir, 'import', catalogXml()));

    $updated = false;
    Category::updated(function () use (&$updated) {
        $updated = true;
    });

    Carbon::setTestNow('2026-09-07 10:03:30');
    $updates = updateQueriesFor('categories', function () {
        (new CatalogImporter(new ProductWhitelist(), 1))->import(writeTempXml($this->tempDir, 'import', catalogXml()));
    });

    $after = Category::where('external_id', 'grp-su-1')->firstOrFail();

    expect($updates)->toBe(0);
    expect($updated)->toBeFalse();
    expect($after->synced_at?->toDateTimeString())->toBe('2026-09-07 10:00:00');
});

test('переименование категории сохраняется и обновляет synced_at', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    (new CatalogImporter(new ProductWhitelist(), 1))->import(writeTempXml($this->tempDir, 'import', catalogXml()));

    Carbon::setTestNow('2026-09-07 10:03:30');
    $updates = updateQueriesFor('categories', function () {
        (new CatalogImporter(new ProductWhitelist(), 1))
            ->import(writeTempXml($this->tempDir, 'import', catalogXml(groupName: 'Молочные продукты')));
    });

    $after = Category::where('external_id', 'grp-su-1')->firstOrFail();

    expect($updates)->toBe(1);
    expect($after->name)->toBe('Молочные продукты');
    expect($after->synced_at?->toDateTimeString())->toBe('2026-09-07 10:03:30');
});

// ---------------------------------------------------------------------------
// RestsImporter: остатки (бесскладовый формат Ласточки)
// ---------------------------------------------------------------------------

test('повторный импорт rests с тем же количеством не делает UPDATE stocks', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    $product = Product::factory()->withoutCategory()->create(['external_id' => 'prod-su-1', 'shop_id' => 1]);
    (new RestsImporter(1))->import(writeTempXml($this->tempDir, 'rests', restsXml('39')));

    Carbon::setTestNow('2026-09-07 10:03:30');
    $updates = updateQueriesFor('stocks', function () {
        (new RestsImporter(1))->import(writeTempXml($this->tempDir, 'rests', restsXml('39')));
    });

    $stock = Stock::where('product_id', $product->id)->sole();

    expect($updates)->toBe(0);
    expect($stock->synced_at?->toDateTimeString())->toBe('2026-09-07 10:00:00');
    expect((float) $stock->quantity)->toBe(39.0);
});

test('изменение количества в rests сохраняется и обновляет synced_at', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    $product = Product::factory()->withoutCategory()->create(['external_id' => 'prod-su-1', 'shop_id' => 1]);
    (new RestsImporter(1))->import(writeTempXml($this->tempDir, 'rests', restsXml('39')));

    Carbon::setTestNow('2026-09-07 10:03:30');
    $updates = updateQueriesFor('stocks', function () {
        (new RestsImporter(1))->import(writeTempXml($this->tempDir, 'rests', restsXml('12,5')));
    });

    $stock = Stock::where('product_id', $product->id)->sole();

    expect($updates)->toBe(1);
    expect((float) $stock->quantity)->toBe(12.5);
    expect($stock->synced_at?->toDateTimeString())->toBe('2026-09-07 10:03:30');
});

// ---------------------------------------------------------------------------
// OffersImporter: остатки (общий + складской)
// ---------------------------------------------------------------------------

test('повторный импорт offers с теми же остатками не делает UPDATE stocks', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    $product = Product::factory()->withoutCategory()->create(['external_id' => 'prod-su-1', 'shop_id' => 1]);
    (new OffersImporter(1))->import(writeTempXml($this->tempDir, 'offers', offersXml('20', '20')));

    expect(Stock::where('product_id', $product->id)->count())->toBe(2);

    Carbon::setTestNow('2026-09-07 10:03:30');
    $updates = updateQueriesFor('stocks', function () {
        (new OffersImporter(1))->import(writeTempXml($this->tempDir, 'offers', offersXml('20', '20')));
    });

    $syncedAt = Stock::where('product_id', $product->id)->pluck('synced_at')
        ->map(fn ($v) => Carbon::parse($v)->toDateTimeString())
        ->unique()
        ->all();

    expect($updates)->toBe(0);
    expect($syncedAt)->toBe(['2026-09-07 10:00:00']);
});

test('изменение складского остатка в offers сохраняет только изменённую строку', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    $product = Product::factory()->withoutCategory()->create(['external_id' => 'prod-su-1', 'shop_id' => 1]);
    (new OffersImporter(1))->import(writeTempXml($this->tempDir, 'offers', offersXml('20', '20')));

    Carbon::setTestNow('2026-09-07 10:03:30');
    $updates = updateQueriesFor('stocks', function () {
        (new OffersImporter(1))->import(writeTempXml($this->tempDir, 'offers', offersXml('20', '7')));
    });

    $total = Stock::where('product_id', $product->id)->whereNull('warehouse_id')->sole();
    $warehouse = Stock::where('product_id', $product->id)->where('warehouse_id', 'wh-su-1')->sole();

    expect($updates)->toBe(1);
    expect($total->synced_at?->toDateTimeString())->toBe('2026-09-07 10:00:00');
    expect((float) $warehouse->quantity)->toBe(7.0);
    expect($warehouse->synced_at?->toDateTimeString())->toBe('2026-09-07 10:03:30');
});

// ---------------------------------------------------------------------------
// CategoriesSyncPublishCondition: страховка от других путей записи
// ---------------------------------------------------------------------------

test('condition категорий не публикует updated, если изменился только synced_at', function () {
    IntegrationConfig::create([
        'shop_id' => 1,
        'shop_code' => 'lastochka',
        'integration_type' => '1c',
        'driver' => '1c',
        'is_active' => true,
        'sync_categories_enabled' => true,
        'credentials' => [],
    ]);

    // fresh(): у экземпляра из factory wasRecentlyCreated=true, а в проде
    // condition получает модель, загруженную из БД на следующем обмене.
    $category = Category::factory()->create(['shop_id' => 1, 'external_id' => 'grp-cond-1', 'name' => 'Сыры'])->fresh();
    $category->synced_at = now()->addMinutes(3);
    $category->save();

    expect($category->wasChanged('synced_at'))->toBeTrue();
    expect((new CategoriesSyncPublishCondition())($category))->toBeFalse();
});

test('condition категорий публикует updated при изменении значимого поля', function () {
    IntegrationConfig::create([
        'shop_id' => 1,
        'shop_code' => 'lastochka',
        'integration_type' => '1c',
        'driver' => '1c',
        'is_active' => true,
        'sync_categories_enabled' => true,
        'credentials' => [],
    ]);

    $category = Category::factory()->create(['shop_id' => 1, 'external_id' => 'grp-cond-2', 'name' => 'Сыры'])->fresh();
    $category->name = 'Сыры и творог';
    $category->save();

    expect((new CategoriesSyncPublishCondition())($category))->toBeTrue();
});
