<?php

namespace Tests\Feature\ProductContent;

use App\Models\Product;
use App\Models\Status;
use App\Services\ProductContent\ImportRowResult;
use App\Services\ProductContent\ProductContentImportService;
use App\Services\ProductContent\ProductContentSchema as Schema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportProductContentCommandTest extends TestCase
{
  use DatabaseTransactions;

  private string $dir;

  protected function setUp(): void
  {
    parent::setUp();
    $this->dir = storage_path('app/testing/import-' . uniqid());
    mkdir($this->dir, 0775, true);
    foreach (Schema::ATTRIBUTE_COLUMNS as $code) {
      \App\Models\Attribute::query()->firstOrCreate(
        ['code' => $code],
        ['title' => $code, 'type_id' => $code === 'composition' ? \App\Models\Type::ATTRIBUTE_TEXT : \App\Models\Type::ATTRIBUTE_STRING, 'category_type_id' => \App\Models\Type::ATTRIBUTE_COMPOSITION, 'position' => 0],
      );
    }
  }

  protected function tearDown(): void
  {
    foreach (glob($this->dir . '/*') ?: [] as $f) {
      @unlink($f);
    }
    @rmdir($this->dir);
    parent::tearDown();
  }

  /** Собрать входной XLSX: заголовки + строки-ассоциативы по именам колонок. */
  private function makeXlsx(array $rows): string
  {
    $path = $this->dir . '/input.xlsx';
    $writer = new Writer();
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(Schema::HEADERS));

    foreach ($rows as $assoc) {
      $row = [];
      foreach (Schema::HEADERS as $header) {
        $row[] = $assoc[$header] ?? '';
      }
      $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return $path;
  }

  private function makeProduct(array $attrs = []): Product
  {
    return Product::query()->create(array_merge([
      'title' => 'Cmd Import ' . uniqid(),
      'status_id' => Status::CODE_ACTIVE,
    ], $attrs));
  }

  /**
   * Строго распарсить CSV-отчёт: снять и проверить UTF-8 BOM, проверить точный
   * набор/порядок заголовков, вернуть строки данных как числовые массивы колонок.
   *
   * @return array<int, array<int, string>>
   */
  private function readCsvReport(string $path): array
  {
    $handle = fopen($path, 'r');
    $this->assertNotFalse($handle);

    $bom = fread($handle, 3);
    $this->assertSame("\xEF\xBB\xBF", $bom, 'CSV-отчёт должен начинаться с UTF-8 BOM');

    $header = fgetcsv($handle, 0, ';');
    $this->assertSame(['id', 'строка', 'поле', 'статус', 'причина'], $header);

    $rows = [];
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
      $rows[] = $row;
    }

    fclose($handle);

    return $rows;
  }

  public function test_imports_file_and_writes_report(): void
  {
    $target = $this->makeProduct();
    $occupied = $this->makeProduct(['description' => 'Занято']);

    $file = $this->makeXlsx([
      [Schema::COL_ID => (string) $target->id, Schema::COL_DESCRIPTION => 'Из файла', Schema::COL_PROTEINS => '10,1'],
      [Schema::COL_ID => (string) $occupied->id, Schema::COL_DESCRIPTION => 'Не должно записаться'],
      [Schema::COL_ID => '99999999', Schema::COL_DESCRIPTION => 'Несуществующий'],
    ]);

    $this->artisan('products:import-content', ['file' => $file])->assertExitCode(0);

    $this->assertSame('Из файла', $target->refresh()->description);
    $this->assertSame('Занято', $occupied->refresh()->description);

    $reports = glob($this->dir . '/input-report-*.csv');
    $this->assertCount(1, $reports);

    $rows = $this->readCsvReport($reports[0]);

    $errorRow = null;
    $statuses = [];
    foreach ($rows as $row) {
      $statuses[] = $row[3];
      if ($row[0] === '99999999') {
        $errorRow = $row;
      }
    }

    $this->assertNotNull($errorRow, 'В отчёте нет записи по несуществующему id 99999999');
    $this->assertSame('99999999', $errorRow[0]);
    $this->assertSame('error', $errorRow[3]);

    $this->assertContains('updated', $statuses);
    $this->assertContains('skipped_filled', $statuses);
  }

  public function test_report_row_numbers_survive_blank_rows(): void
  {
    $target = $this->makeProduct();

    $file = $this->makeXlsx([
      [Schema::COL_ID => (string) $target->id, Schema::COL_DESCRIPTION => 'Из файла'], // rowNumber=2
      [], // rowNumber=3 — полностью пустая строка: openspout Writer её не пишет в XML, но счётчик строк сохраняет
      [Schema::COL_ID => '99999999', Schema::COL_DESCRIPTION => 'Несуществующий'], // rowNumber=4
    ]);

    $this->artisan('products:import-content', ['file' => $file])->assertExitCode(0);

    $reports = glob($this->dir . '/input-report-*.csv');
    $this->assertCount(1, $reports);

    $rows = $this->readCsvReport($reports[0]);

    $errorRow = null;
    foreach ($rows as $row) {
      if ($row[0] === '99999999') {
        $errorRow = $row;
      }
    }

    $this->assertNotNull($errorRow, 'В отчёте нет записи по несуществующему id 99999999');
    $this->assertSame(
      '4',
      $errorRow[1],
      'Номер строки в отчёте должен указывать на реальную строку Excel (4), а не на счётчик непустых строк (3)',
    );
  }

  public function test_corrupted_xlsx_fails_gracefully(): void
  {
    $path = $this->dir . '/corrupted.xlsx';
    file_put_contents($path, 'это не xlsx, а мусор');

    $this->artisan('products:import-content', ['file' => $path])->assertExitCode(1);
  }

  /**
   * Русский Excel любит молча превращать «8.6» в дату при автоформате ячейки.
   * openspout без SHOULD_FORMAT_DATES отдаёт такую ячейку объектом
   * DateTimeImmutable, и (string) на нём рушит весь импорт — Error, поймана
   * catch(\Throwable), отчёта нет вообще. Собираем файл вручную (не через
   * makeXlsx) — только так можно дать ячейке стиль с датовым числовым
   * форматом, без которого openspout читает её просто как число.
   */
  public function test_date_formatted_cell_does_not_crash_import(): void
  {
    $target = $this->makeProduct();

    $path = $this->dir . '/input.xlsx';
    $writer = new Writer();
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(Schema::HEADERS));

    $dateStyle = (new Style())->setFormat('dd.mm.yyyy');

    $cells = [];
    foreach (Schema::HEADERS as $header) {
      $cells[] = match ($header) {
        Schema::COL_ID => Cell::fromValue((string) $target->id),
        Schema::COL_PROTEINS => Cell::fromValue(new \DateTimeImmutable('2026-06-08'), $dateStyle),
        default => Cell::fromValue(''),
      };
    }
    $writer->addRow(new Row($cells));
    $writer->close();

    $this->artisan('products:import-content', ['file' => $path])->assertExitCode(0);

    $reports = glob($this->dir . '/input-report-*.csv');
    $this->assertCount(1, $reports, 'Отчёт обязан быть создан даже при date-ячейке в файле');

    $rows = $this->readCsvReport($reports[0]);

    $proteinsRow = null;
    foreach ($rows as $row) {
      if ($row[0] === (string) $target->id && $row[2] === Schema::COL_PROTEINS) {
        $proteinsRow = $row;
      }
    }

    // Главное — команда не упала. Само значение даты не проходит числовую
    // валидацию «Белков», допустимы оба варианта: error в отчёте либо
    // отсутствие записи по этой колонке вовсе.
    if ($proteinsRow !== null) {
      $this->assertSame('error', $proteinsRow[3]);
    }
  }

  /**
   * Оба внешних catch раньше покрывали весь цикл целиком: исключение сервиса
   * на строке N выбрасывало накопленные $results, хотя строка N-1 уже была
   * закоммичена в БД (транзакция per-row). Строка сервиса должна ловиться
   * локально и не терять уже посчитанные строки отчёта.
   */
  public function test_partial_report_saved_when_mid_import_row_throws(): void
  {
    $rowA = $this->makeProduct();
    $rowB = $this->makeProduct();

    $file = $this->makeXlsx([
      [Schema::COL_ID => (string) $rowA->id, Schema::COL_DESCRIPTION => 'A'], // rowNumber=2
      [Schema::COL_ID => (string) $rowB->id, Schema::COL_DESCRIPTION => 'B'], // rowNumber=3
    ]);

    $calls = 0;
    $mock = $this->createMock(ProductContentImportService::class);
    $mock->method('importRow')->willReturnCallback(function () use (&$calls, $rowA) {
      $calls++;
      if ($calls === 1) {
        return new ImportRowResult($rowA->id, 2, updated: [Schema::COL_DESCRIPTION]);
      }

      throw new \RuntimeException('строка B сломалась');
    });

    $this->app->instance(ProductContentImportService::class, $mock);

    $this->artisan('products:import-content', ['file' => $file])->assertExitCode(0);

    $reports = glob($this->dir . '/input-report-*.csv');
    $this->assertCount(1, $reports);

    $rows = $this->readCsvReport($reports[0]);

    $rowAReport = null;
    $rowBReport = null;
    foreach ($rows as $row) {
      if ($row[0] === (string) $rowA->id) {
        $rowAReport = $row;
      }
      if ($row[1] === '3') {
        $rowBReport = $row;
      }
    }

    $this->assertNotNull($rowAReport, 'В отчёте должна остаться запись по успешно обработанной строке A');
    $this->assertSame('updated', $rowAReport[3]);

    $this->assertNotNull($rowBReport, 'В отчёте должна остаться error-запись по сломавшейся строке B');
    $this->assertSame('error', $rowBReport[3]);
    $this->assertStringContainsString('необработанная ошибка', $rowBReport[4]);
  }

  public function test_dry_run_changes_nothing_but_still_reports(): void
  {
    $target = $this->makeProduct();

    $file = $this->makeXlsx([
      [Schema::COL_ID => (string) $target->id, Schema::COL_DESCRIPTION => 'Dry'],
    ]);

    $this->artisan('products:import-content', ['file' => $file, '--dry-run' => true])
      ->assertExitCode(0);

    $this->assertNull($target->refresh()->description);
    $this->assertCount(1, glob($this->dir . '/input-report-*.csv'));
  }

  public function test_missing_file_and_missing_id_column_fail(): void
  {
    $this->artisan('products:import-content', ['file' => $this->dir . '/nope.xlsx'])
      ->assertExitCode(1);

    // Файл без колонки id
    $path = $this->dir . '/input.xlsx';
    $writer = new Writer();
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Название', 'Описание']));
    $writer->close();

    $this->artisan('products:import-content', ['file' => $path])->assertExitCode(1);
  }
}
