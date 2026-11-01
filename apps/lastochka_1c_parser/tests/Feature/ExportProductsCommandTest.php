<?php

use App\Models\Category;
use App\Models\Image;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::makeDirectory('exports');
});

/**
 * Строки xlsx, который сохранила команда products:export (строка 0 — заголовки).
 *
 * Читаем реальный файл, а не Excel::fake(): фейк ничего не пишет на диск,
 * и команда падает на Storage::size() после Excel::store().
 * Пустые ячейки читаются как '' — в xlsx пустая строка и отсутствие значения неотличимы.
 */
function exportProductsXlsxRows(): array
{
    $files = Storage::files('exports');
    expect($files)->toHaveCount(1);

    return IOFactory::load(Storage::path($files[0]))->getActiveSheet()->toArray('');
}

test('команда экспорта создаёт xlsx файл', function () {
    $category = Category::factory()->create(['name' => 'Тестовая категория']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Тестовый товар',
        'sku' => 'TEST-001',
    ]);
    Stock::factory()->create(['product_id' => $product->id, 'quantity' => 10]);

    $this->artisan('products:export')
        ->assertSuccessful();

    $files = Storage::files('exports');
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.xlsx');
});

test('файл содержит корректные заголовки', function () {
    Product::factory()->create();

    $this->artisan('products:export')
        ->assertSuccessful();

    expect(exportProductsXlsxRows()[0])->toBe([
        'Название товара',
        'Товарная группа',
        'Описание',
        'Ссылка в магазине',
        'Ссылка на изображение',
        'Артикул',
        'Штрих-код',
        'Цена',
        'Количество',
        'Внешний ID',
        'Xml ID',
        'Длина',
        'Ширина',
        'Высота',
        'Вес',
    ]);
});

test('артикул подставляется как 1C-{id} если sku пустой', function () {
    $product = Product::factory()->withoutSku()->create();

    $this->artisan('products:export')
        ->assertSuccessful();

    // Артикул — 6й элемент (индекс 5) в строке товара (индекс 1)
    expect(exportProductsXlsxRows()[1][5])->toBe("1C-{$product->id}");
});

test('опция --active-only фильтрует неактивные товары', function () {
    Product::factory()->count(3)->create(['is_active' => true]);
    Product::factory()->count(2)->inactive()->create();

    $this->artisan('products:export', ['--active-only' => true])
        ->assertSuccessful();

    // Проверяем что в выборке только 3 товара
    $export = new \App\Exports\ProductsExport(activeOnly: true);
    expect($export->query()->count())->toBe(3);
});

test('опция --in-stock фильтрует товары без остатков', function () {
    $productWithStock = Product::factory()->create();
    Stock::factory()->create(['product_id' => $productWithStock->id, 'quantity' => 10]);

    $productWithoutStock = Product::factory()->create();
    Stock::factory()->empty()->create(['product_id' => $productWithoutStock->id]);

    $productNoStocks = Product::factory()->create();

    $export = new \App\Exports\ProductsExport(inStockOnly: true);
    expect($export->query()->count())->toBe(1);
});

test('создаётся пустой файл с заголовками если нет товаров', function () {
    $this->artisan('products:export')
        ->expectsOutput('В базе нет товаров, соответствующих критериям.')
        ->assertSuccessful();

    $files = Storage::files('exports');
    expect($files)->toHaveCount(1);
});

test('опция --output позволяет указать имя файла', function () {
    Product::factory()->create();

    $this->artisan('products:export', ['--output' => 'custom_name.xlsx'])
        ->assertSuccessful();

    expect(Storage::exists('exports/custom_name.xlsx'))->toBeTrue();
});

test('товар без категории экспортируется с пустой товарной группой', function () {
    $product = Product::factory()->withoutCategory()->create();

    $this->artisan('products:export')
        ->assertSuccessful();

    $row = exportProductsXlsxRows()[1];
    expect($row[0])->toBe($product->name);
    // Товарная группа — 2й элемент (индекс 1)
    expect($row[1])->toBe('');
});

test('количество суммируется по всем складам', function () {
    $product = Product::factory()->create();
    Stock::factory()->create(['product_id' => $product->id, 'quantity' => 10]);
    Stock::factory()->create(['product_id' => $product->id, 'quantity' => 25.5]);

    $this->artisan('products:export')
        ->assertSuccessful();

    // Количество — 9й элемент (индекс 8) в строке товара (индекс 1)
    expect((float) exportProductsXlsxRows()[1][8])->toBe(35.5);
});

test('URL изображения формируется с полным доменом', function () {
    config(['app.url' => 'https://example.com']);
    config(['export.image_base_url' => null]);

    $product = Product::factory()->create();
    Image::factory()->main()->create([
        'product_id' => $product->id,
        'path' => 'products/test-image.jpg',
    ]);

    $export = new \App\Exports\ProductsExport();
    $product->load('mainImage');
    $mapped = $export->map($product);

    // Ссылка на изображение — 5й элемент (индекс 4)
    expect($mapped[4])->toStartWith('https://example.com/');
});

test('атрибуты извлекаются по маппингу', function () {
    $product = Product::factory()->create([
        'attributes' => [
            'Длина' => 100,
        ],
    ]);

    $export = new \App\Exports\ProductsExport();
    $mapped = $export->map($product);

    // Длина — индекс 11
    expect($mapped[11])->toBe('100');
});
