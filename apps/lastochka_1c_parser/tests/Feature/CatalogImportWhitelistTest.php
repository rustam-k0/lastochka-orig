<?php

use App\Models\Product;
use App\Services\Exchange1C\CatalogImporter;
use App\Services\Exchange1C\ProductWhitelist;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Whitelist строим через fromShopConfig(): с multishop-рефакторинга (12c5185) конструктор
// ProductWhitelist не читает exchange1c.whitelist_*, а без аргумента означает «выключен».
// CatalogImporter — всегда с shopId, как в ImportCatalogJob: products.shop_id — NOT NULL.

beforeEach(function () {
    // Создаём временные файлы для тестов
    $this->tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();
    mkdir($this->tempDir, 0777, true);

    $this->tempCsvPath = $this->tempDir . '/whitelist.csv';
    $this->tempXmlPath = $this->tempDir . '/import.xml';
});

afterEach(function () {
    // Удаляем временные файлы
    if (isset($this->tempXmlPath) && file_exists($this->tempXmlPath)) {
        unlink($this->tempXmlPath);
    }
    if (isset($this->tempCsvPath) && file_exists($this->tempCsvPath)) {
        unlink($this->tempCsvPath);
    }
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

test('import filters products by barcode whitelist', function () {
    // Создаём whitelist с 2 штрихкодами
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    $csv .= "2,Бананы кг,-,\"140,0\",кг,2200647\n";
    file_put_contents($this->tempCsvPath, $csv);

    // Создаём XML с 4 товарами (2 со штрихкодами из whitelist, 2 нет)
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>product-1</Ид>
                <Наименование>Апельсины</Наименование>
                <Штрихкод>2200569</Штрихкод>
            </Товар>
            <Товар>
                <Ид>product-2</Ид>
                <Наименование>Бананы</Наименование>
                <Штрихкод>2200647</Штрихкод>
            </Товар>
            <Товар>
                <Ид>product-3</Ид>
                <Наименование>Кокос</Наименование>
                <Штрихкод>9999999</Штрихкод>
            </Товар>
            <Товар>
                <Ид>product-4</Ид>
                <Наименование>Ананас</Наименование>
                <Штрихкод>8888888</Штрихкод>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
    file_put_contents($this->tempXmlPath, $xml);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();
    $importer = new CatalogImporter($whitelist, 1);
    $result = $importer->import($this->tempXmlPath);

    // Должно быть импортировано 2, пропущено 2
    expect($result['products'])->toBe(2);
    expect($result['skipped_whitelist'])->toBe(2);

    // В БД должно быть только 2 товара
    expect(Product::count())->toBe(2);
    expect(Product::where('name', 'Апельсины')->exists())->toBeTrue();
    expect(Product::where('name', 'Бананы')->exists())->toBeTrue();
    expect(Product::where('name', 'Кокос')->exists())->toBeFalse();
    expect(Product::where('name', 'Ананас')->exists())->toBeFalse();
});

test('import allows all products when whitelist disabled', function () {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>product-1</Ид>
                <Наименование>Апельсины</Наименование>
                <Штрихкод>2200569</Штрихкод>
            </Товар>
            <Товар>
                <Ид>product-2</Ид>
                <Наименование>Кокос</Наименование>
                <Штрихкод>9999999</Штрихкод>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
    file_put_contents($this->tempXmlPath, $xml);

    config(['exchange1c.whitelist_enabled' => false]);

    $whitelist = ProductWhitelist::fromShopConfig();
    $importer = new CatalogImporter($whitelist, 1);
    $result = $importer->import($this->tempXmlPath);

    // Все товары должны быть импортированы
    expect($result['products'])->toBe(2);
    expect($result['skipped_whitelist'])->toBe(0);
    expect(Product::count())->toBe(2);
});

test('import allows product with multiple barcodes when one matches', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    file_put_contents($this->tempCsvPath, $csv);

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>product-1</Ид>
                <Наименование>Апельсины свежие</Наименование>
                <Штрихкод>1111111</Штрихкод>
                <Штрихкод>2200569</Штрихкод>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
    file_put_contents($this->tempXmlPath, $xml);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();
    $importer = new CatalogImporter($whitelist, 1);
    $result = $importer->import($this->tempXmlPath);

    // Товар с несколькими штрихкодами должен пройти, т.к. один из них в whitelist
    expect($result['products'])->toBe(1);
    expect($result['skipped_whitelist'])->toBe(0);
});

test('import persists product code from Код requisite', function () {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>product-code-1</Ид>
                <Наименование>Апельсины</Наименование>
                <Артикул>ART-777</Артикул>
                <Штрихкод>2200569</Штрихкод>
                <ЗначенияРеквизитов>
                    <ЗначениеРеквизита>
                        <Наименование>Код</Наименование>
                        <Значение>00042</Значение>
                    </ЗначениеРеквизита>
                </ЗначенияРеквизитов>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
    file_put_contents($this->tempXmlPath, $xml);

    config(['exchange1c.whitelist_enabled' => false]);

    $whitelist = ProductWhitelist::fromShopConfig();
    // shopId обязателен: в проде importer всегда строится с shop_id (multishop),
    // а products.shop_id — NOT NULL.
    $importer = new CatalogImporter($whitelist, 1);
    $importer->import($this->tempXmlPath);

    $product = Product::where('external_id', 'product-code-1')->first();

    expect($product)->not->toBeNull();
    // Код хранится отдельно от артикула
    expect($product->code)->toBe('00042');
    expect($product->sku)->toBe('ART-777');
});

test('import skips products without barcodes', function () {
    $csv = "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n";
    $csv .= "1,\"Апельсины, кг\",-,\"152,0\",кг,2200569\n";
    file_put_contents($this->tempCsvPath, $csv);

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>product-1</Ид>
                <Наименование>Апельсины</Наименование>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
    file_put_contents($this->tempXmlPath, $xml);

    config(['exchange1c.whitelist_enabled' => true]);
    config(['exchange1c.whitelist_path' => $this->tempCsvPath]);

    $whitelist = ProductWhitelist::fromShopConfig();
    $importer = new CatalogImporter($whitelist, 1);
    $result = $importer->import($this->tempXmlPath);

    // Товар без штрихкода не должен импортироваться
    expect($result['products'])->toBe(0);
    expect($result['skipped_whitelist'])->toBe(1);
});
