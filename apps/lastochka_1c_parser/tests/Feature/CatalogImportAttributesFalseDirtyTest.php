<?php

declare(strict_types=1);

use App\Models\Product;
use App\RabbitMQ\Conditions\ProductSyncPublishCondition;
use App\Services\Exchange1C\CatalogImporter;
use App\Services\Exchange1C\ProductWhitelist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Регресс на лавину product.updated (2026-07-07).
 *
 * MySQL-колонка JSON нормализует объекты — пересортировывает ключи (по длине,
 * затем по алфавиту): parseAttributes строит ['unit','unit_code','calories',...],
 * а из БД массив возвращается как ['nds','fats','unit',...]. Eloquent сравнивает
 * array-cast порядко-чувствительным `===` → attributes «изменился» на каждом
 * импорте каждого товара → ProductSyncPublishCondition (wasChanged) пропускал
 * событие на КАЖДЫЙ товар КАЖДОЙ выгрузки (88k событий/день при каталоге 13k).
 *
 * SQLite не нормализует JSON, поэтому порядок ключей БД эмулируется явным
 * UPDATE сырого JSON в MySQL-каноничном порядке.
 */
beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->tempXmlPath = $this->tempDir . '/import.xml';

    config(['exchange1c.whitelist_enabled' => false]);
});

afterEach(function () {
    if (file_exists($this->tempXmlPath)) {
        unlink($this->tempXmlPath);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

/**
 * XML товара с КБЖУ/НДС — parseAttributes соберёт ключи в порядке
 * unit, unit_code, calories, proteins, fats, carbs, nds.
 */
function falseDirtyProductXml(string $proteins = '0'): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>product-fd-1</Ид>
                <Наименование>Хек тушка 200-300</Наименование>
                <Артикул>44238</Артикул>
                <Штрихкод>0000000002444</Штрихкод>
                <БазоваяЕдиница Код="166">кг</БазоваяЕдиница>
                <ЗначенияРеквизитов>
                    <ЗначениеРеквизита>
                        <Наименование>Калорийность</Наименование>
                        <Значение>86</Значение>
                    </ЗначениеРеквизита>
                    <ЗначениеРеквизита>
                        <Наименование>Белки</Наименование>
                        <Значение>{$proteins}</Значение>
                    </ЗначениеРеквизита>
                    <ЗначениеРеквизита>
                        <Наименование>Жиры</Наименование>
                        <Значение>2.2</Значение>
                    </ЗначениеРеквизита>
                    <ЗначениеРеквизита>
                        <Наименование>Углеводы</Наименование>
                        <Значение>0</Значение>
                    </ЗначениеРеквизита>
                    <ЗначениеРеквизита>
                        <Наименование>НДС</Наименование>
                        <Значение>10</Значение>
                    </ЗначениеРеквизита>
                </ЗначенияРеквизитов>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
}

/**
 * Эмуляция нормализации MySQL JSON: те же пары ключ-значение, но порядок
 * ключей «по длине, затем по алфавиту» (как хранит MySQL), не как в PHP.
 */
function simulateMysqlJsonNormalization(Product $product): void
{
    $attributes = $product->getAttribute('attributes');

    $normalized = [];
    $keys = array_keys($attributes);
    usort($keys, fn (string $a, string $b) => [mb_strlen($a), $a] <=> [mb_strlen($b), $b]);
    foreach ($keys as $key) {
        $normalized[$key] = $attributes[$key];
    }

    DB::table('products')
        ->where('id', $product->id)
        ->update(['attributes' => json_encode($normalized, JSON_UNESCAPED_UNICODE)]);
}

test('повторный импорт без изменений при MySQL-порядке ключей attributes не помечает attributes изменённым', function () {
    file_put_contents($this->tempXmlPath, falseDirtyProductXml());

    $importer = new CatalogImporter(new ProductWhitelist(), 1);
    $importer->import($this->tempXmlPath);

    $product = Product::where('external_id', 'product-fd-1')->firstOrFail();
    simulateMysqlJsonNormalization($product);
    // Прошлый обмен был давно. Раньше synced_at=now() гарантировал UPDATE и тест
    // проверял, что attributes в него не попал. С 2026-09-07 synced_at ставится
    // только при реальном изменении (ImportSkipUnchangedTest), поэтому ложно-грязный
    // attributes должен дать полное отсутствие UPDATE, а не UPDATE без attributes.
    DB::table('products')->where('id', $product->id)->update(['synced_at' => '2026-01-01 00:00:00']);

    /** @var Product|null $saved */
    $saved = null;
    Product::updated(function (Product $p) use (&$saved) {
        $saved = $p;
    });

    (new CatalogImporter(new ProductWhitelist(), 1))->import($this->tempXmlPath);

    expect($saved)->toBeNull('attributes ложно-грязный — UPDATE не должен произойти вовсе');

    $fresh = Product::where('external_id', 'product-fd-1')->firstOrFail();
    expect($fresh->synced_at?->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

test('реальное изменение значения атрибута по-прежнему публикуется', function () {
    file_put_contents($this->tempXmlPath, falseDirtyProductXml(proteins: '0'));

    $importer = new CatalogImporter(new ProductWhitelist(), 1);
    $importer->import($this->tempXmlPath);

    $product = Product::where('external_id', 'product-fd-1')->firstOrFail();
    simulateMysqlJsonNormalization($product);
    DB::table('products')->where('id', $product->id)->update(['synced_at' => '2026-01-01 00:00:00']);

    /** @var Product|null $saved */
    $saved = null;
    Product::updated(function (Product $p) use (&$saved) {
        $saved = $p;
    });

    // Белки: 0 → 16.6 — содержательное изменение
    file_put_contents($this->tempXmlPath, falseDirtyProductXml(proteins: '16.6'));
    (new CatalogImporter(new ProductWhitelist(), 1))->import($this->tempXmlPath);

    expect($saved)->not->toBeNull();
    expect(array_keys($saved->getChanges()))->toContain('attributes');
    expect((new ProductSyncPublishCondition())($saved))->toBeTrue();

    $fresh = Product::where('external_id', 'product-fd-1')->firstOrFail();
    expect($fresh->getAttribute('attributes')['proteins'])->toBe('16.6');
});
