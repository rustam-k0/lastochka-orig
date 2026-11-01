<?php

namespace Tests\Feature\ProductContent;

use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Status;
use App\Models\Type;
use App\Services\ProductContent\ProductContentSchema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class ExportProductContentCommandTest extends TestCase
{
  use DatabaseTransactions;

  private string $path;

  protected function setUp(): void
  {
    parent::setUp();
    $this->path = storage_path('app/testing/export-' . uniqid() . '.xlsx');
  }

  protected function tearDown(): void
  {
    @unlink($this->path);
    parent::tearDown();
  }

  /**
   * @return array{sheets: string[], rows: array<int, array<int, string>>, instructionRows: array<int, array<int, string>>}
   *   rows — строки первого листа («Товары»), instructionRows — строки второго листа («Инструкция»)
   */
  private function readXlsx(string $path): array
  {
    $reader = new Reader();
    $reader->open($path);

    $sheets = [];
    $rows = [];
    $instructionRows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
      $sheets[] = $sheet->getName();
      if (count($sheets) === 1) {
        foreach ($sheet->getRowIterator() as $row) {
          $rows[] = array_map(fn ($v) => (string) $v, $row->toArray());
        }
      } elseif (count($sheets) === 2) {
        foreach ($sheet->getRowIterator() as $row) {
          $instructionRows[] = array_map(fn ($v) => (string) $v, $row->toArray());
        }
      }
    }

    $reader->close();

    return ['sheets' => $sheets, 'rows' => $rows, 'instructionRows' => $instructionRows];
  }

  public function test_creates_xlsx_with_two_sheets_headers_and_product_row(): void
  {
    $product = Product::query()->create([
      'title' => 'Command Export ' . uniqid(),
      'status_id' => Status::CODE_ACTIVE,
    ]);

    $this->artisan('products:export-content', ['--path' => $this->path])
      ->assertExitCode(0);

    $this->assertFileExists($this->path);

    $result = $this->readXlsx($this->path);

    $this->assertSame(['Товары', 'Инструкция'], $result['sheets']);
    $this->assertSame(ProductContentSchema::HEADERS, $result['rows'][0]);

    $ids = array_column(array_slice($result['rows'], 1), 0);
    $this->assertContains((string) $product->id, $ids);

    // Лист «Инструкция» — не пустой и в точности соответствует ProductContentSchema::instructionRows()
    $this->assertNotEmpty($result['instructionRows']);
    $expectedInstructionRows = array_values(array_filter(
      array_map(
        fn (array $row) => array_map(fn ($v) => (string) $v, $row),
        ProductContentSchema::instructionRows(),
      ),
      // OpenSpout XLSX Writer не пишет полностью пустые строки (все ячейки '') —
      // такая строка-разделитель отсутствует и в файле после записи/чтения
      fn (array $row) => trim(implode('', $row)) !== '',
    ));
    $this->assertSame($expectedInstructionRows, $result['instructionRows']);
  }

  public function test_only_unfilled_excludes_filled_product(): void
  {
    // Товар с полным контентом собираем так же, как в тесте сервиса
    $filled = Product::query()->create([
      'title' => 'Filled ' . uniqid(),
      'status_id' => Status::CODE_ACTIVE,
      'description' => 'Полное описание',
    ]);
    foreach (ProductContentSchema::ATTRIBUTE_COLUMNS as $code) {
      $attribute = Attribute::query()->firstOrCreate(
        ['code' => $code],
        ['title' => $code, 'type_id' => $code === 'composition' ? Type::ATTRIBUTE_TEXT : Type::ATTRIBUTE_STRING, 'category_type_id' => Type::ATTRIBUTE_COMPOSITION, 'position' => 0],
      );
      ProductAttribute::query()->create([
        'product_id' => $filled->id,
        'attribute_id' => $attribute->id,
        $attribute->type_id === Type::ATTRIBUTE_TEXT ? 'text_value' : 'string_value' => 'значение',
      ]);
    }
    $filled->saveImg(['path' => '/files/x.jpg', 'origin_name' => 'x.jpg'], Status::CODE_HEAD_IMG);

    $unfilled = Product::query()->create([
      'title' => 'Unfilled ' . uniqid(),
      'status_id' => Status::CODE_ACTIVE,
    ]);

    $this->artisan('products:export-content', ['--path' => $this->path, '--only-unfilled' => true])
      ->assertExitCode(0);

    $ids = array_column(array_slice($this->readXlsx($this->path)['rows'], 1), 0);

    $this->assertNotContains((string) $filled->id, $ids);
    $this->assertContains((string) $unfilled->id, $ids);
  }

  public function test_default_path_used_when_no_path_option(): void
  {
    $beforeRun = time();

    $this->artisan('products:export-content')
      ->assertExitCode(0);

    $files = glob(storage_path('app/exports/product-content-*.xlsx')) ?: [];
    $this->assertNotEmpty($files, 'Файл выгрузки по дефолтному пути (storage/app/exports) не найден');

    // Берём самый свежий файл по mtime — на диске могли остаться файлы прошлых прогонов
    usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
    $latest = $files[0];

    $this->assertGreaterThanOrEqual($beforeRun, filemtime($latest));
    $this->assertMatchesRegularExpression('/product-content-\d{8}-\d{6}\.xlsx$/', $latest);

    // Чистим только созданный этим тестом файл, не всю директорию exports
    @unlink($latest);
  }
}
