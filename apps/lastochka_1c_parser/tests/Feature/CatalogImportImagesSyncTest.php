<?php

use App\Models\Image;
use App\Models\IntegrationConfig;
use App\Models\Product;
use App\Services\Exchange1C\CatalogImporter;
use App\Services\Exchange1C\ProductWhitelist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const SHOP_ID = 4;

beforeEach(function () {
    Storage::fake('public');
    config(['exchange1c.whitelist_enabled' => false]);

    $this->tempDir = sys_get_temp_dir() . '/test_img_sync_' . uniqid();
    mkdir($this->tempDir . '/import_files', 0777, true);

    // Реальный файл-картинка, на который ссылается тег <Картинка> в XML
    $this->imageRelPath = 'import_files/img1.jpg';
    file_put_contents($this->tempDir . '/' . $this->imageRelPath, 'FAKE_JPEG_BYTES');

    $this->xmlPath = $this->tempDir . '/import.xml';

    // XML с одним товаром и одной картинкой
    file_put_contents($this->xmlPath, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>product-img-1</Ид>
                <Наименование>Товар с картинкой</Наименование>
                <Картинка>{$this->imageRelPath}</Картинка>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML);
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tempDir);
    }
});

test('изображения не синхронизируются когда конфига нет (флаг по умолчанию выключен)', function () {
    $importer = new CatalogImporter(new ProductWhitelist(), SHOP_ID);
    $result = $importer->import($this->xmlPath);

    expect($result['products'])->toBe(1);
    expect($result['images'])->toBe(0);
    expect(Image::count())->toBe(0);
});

test('изображения не синхронизируются когда конфиг есть, но sync_images_enabled=false', function () {
    IntegrationConfig::create([
        'integration_type' => '1c',
        'driver' => 'commerceml',
        'shop_id' => SHOP_ID,
        'sync_images_enabled' => false,
        'credentials' => [],
    ]);

    $importer = new CatalogImporter(new ProductWhitelist(), SHOP_ID);
    $result = $importer->import($this->xmlPath);

    expect($result['images'])->toBe(0);
    expect(Image::count())->toBe(0);
});

test('изображения синхронизируются когда sync_images_enabled включён', function () {
    IntegrationConfig::create([
        'integration_type' => '1c',
        'driver' => 'commerceml',
        'shop_id' => SHOP_ID,
        'sync_images_enabled' => true,
        'credentials' => [],
    ]);

    $importer = new CatalogImporter(new ProductWhitelist(), SHOP_ID);
    $result = $importer->import($this->xmlPath);

    expect($result['products'])->toBe(1);
    expect($result['images'])->toBe(1);
    expect(Image::count())->toBe(1);

    $product = Product::where('external_id', 'product-img-1')->first();
    expect($product->images()->count())->toBe(1);
    expect($product->images()->first()->is_main)->toBeTrue();
});

test('существующие изображения НЕ удаляются и НЕ заменяются при выключенном флаге', function () {
    // Товар с вручную заведённым в магазине изображением
    $product = Product::create([
        'external_id' => 'product-img-1',
        'shop_id' => SHOP_ID,
        'name' => 'Товар с картинкой',
        'is_active' => true,
    ]);
    $product->images()->create([
        'path' => 'manual/existing.jpg',
        'is_main' => true,
        'sort' => 0,
    ]);

    // Импорт того же товара с картинкой в XML, но флаг выключен (конфига нет)
    $importer = new CatalogImporter(new ProductWhitelist(), SHOP_ID);
    $importer->import($this->xmlPath);

    $product->refresh();
    // Существующее изображение на месте, новое из 1С не добавлено, путь не изменился
    expect($product->images()->count())->toBe(1);
    expect($product->images()->first()->path)->toBe('manual/existing.jpg');
    expect(Image::count())->toBe(1);
});

test('флаг sync_images_enabled читается с учётом shop_id магазина', function () {
    // Конфиг включён для магазина 5, но импорт идёт для магазина 7 → выключено
    IntegrationConfig::create([
        'integration_type' => '1c',
        'driver' => 'commerceml',
        'shop_id' => 5,
        'sync_images_enabled' => true,
        'credentials' => [],
    ]);

    $importer = new CatalogImporter(new ProductWhitelist(), 7);
    $result = $importer->import($this->xmlPath);

    expect($result['images'])->toBe(0);
    expect(Image::count())->toBe(0);
});
