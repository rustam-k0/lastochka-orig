<?php

namespace App\Console\Commands;

use App\Services\ProductContent\ImportRowResult;
use App\Services\ProductContent\ProductContentImportService;
use App\Services\ProductContent\ProductContentSchema;
use Illuminate\Console\Command;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Обратный импорт контента товаров из XLSX парсера (LASTOCHKI-JAMI-12027).
 *
 * Обновляет по id только контентные поля (только пустые без --overwrite),
 * скачивает фото по URL, пишет CSV-отчёт рядом с входным файлом.
 * Первый запуск всегда с --dry-run.
 */
class ImportProductContent extends Command
{
  protected $signature = 'products:import-content
    {file : Путь к заполненному XLSX}
    {--dry-run : Прогон с валидацией и отчётом, без записи в БД}
    {--overwrite : Перезаписывать уже заполненные значения (пустые ячейки всё равно ничего не стирают)}';

  protected $description = 'Импорт контента товаров из заполненного парсером XLSX (LASTOCHKI-JAMI-12027)';

  public function handle(ProductContentImportService $service): int
  {
    $file = $this->argument('file');

    if (!is_file($file)) {
      $this->error("Файл не найден: {$file}");
      return self::FAILURE;
    }

    $dryRun = (bool) $this->option('dry-run');
    $overwrite = (bool) $this->option('overwrite');

    // SHOULD_PRESERVE_EMPTY_ROWS — иначе RowIterator::key() отдаёт счётчик
    // прочитанных непустых строк, и после пустой строки в файле нумерация
    // в CSV-отчёте расходится с реальными номерами строк Excel.
    // SHOULD_FORMAT_DATES — русский Excel любит молча превращать «8.6» и подобные
    // значения в дату при автоформате ячейки. Без этой опции openspout отдаёт
    // такие ячейки объектами DateTimeImmutable, а не строками, и (string) на них
    // кидает Error, роняя весь импорт. С опцией ячейка приходит отформатированной
    // строкой — её просто не пройдёт числовая валидация конкретного поля.
    $options = new Options();
    $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
    $options->SHOULD_FORMAT_DATES = true;
    $reader = new Reader($options);

    $headers = null;
    $results = [];
    $bar = null;

    try {
      $reader->open($file);

      foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $rowNumber => $row) {
          $values = array_map(fn ($v) => (string) $v, $row->toArray());

          // Полностью пустая строка (в т.ч. «виртуальная» из-за preserve-empty-rows) — не ошибка, пропускаем молча
          if ($this->isBlankRow($values)) {
            continue;
          }

          if ($headers === null) {
            $headers = $values;
            if (!in_array(ProductContentSchema::COL_ID, $headers, true)) {
              $this->error('В файле нет колонки «' . ProductContentSchema::COL_ID . '» — это не файл выгрузки.');
              return self::FAILURE;
            }
            $this->warnAboutMissingColumns($headers);

            // Бар стартуем только после успешной валидации заголовков — иначе при FAILURE он остаётся висеть недоделанным
            $bar = $this->output->createProgressBar();
            $bar->start();
            continue;
          }

          $assoc = [];
          foreach ($headers as $i => $header) {
            $assoc[$header] = $values[$i] ?? '';
          }

          try {
            $results[] = $service->importRow($assoc, $rowNumber, $overwrite, $dryRun);
          } catch (\Throwable $e) {
            // Строка N уже могла закоммититься в БД (транзакция per-row) — не даём исключению
            // выкинуть накопленные $results наружу, иначе отчёт по успешным строкам пропадёт.
            $results[] = new ImportRowResult(null, $rowNumber, errors: [
              'строка' => 'необработанная ошибка: ' . $e->getMessage(),
            ]);
          }
          $bar->advance();
        }
        break; // только первый лист («Товары»)
      }
    } catch (OpenSpoutException $e) {
      // Битый/не-zip XLSX, повреждённый архив и т.п. — все read-исключения openspout наследуются от OpenSpoutException
      return $this->failWithPartialReport($file, $results, "Не удалось прочитать файл: {$e->getMessage()}");
    } catch (\Throwable $e) {
      // Страховка на случай непредвиденной ошибки чтения вне иерархии openspout
      return $this->failWithPartialReport($file, $results, "Не удалось прочитать файл: {$e->getMessage()}");
    } finally {
      $reader->close();
    }

    if ($bar !== null) {
      $bar->finish();
      $this->newLine(2);
    }

    if ($headers === null) {
      $this->error('Файл пуст.');
      return self::FAILURE;
    }

    $reportPath = $this->writeReport($file, $results);
    $this->printSummary($results, $reportPath, $dryRun);

    return self::SUCCESS;
  }

  /**
   * Ошибка чтения файла (битый архив, обрыв итерации и т.п.) на середине импорта.
   * Часть строк перед сбоем уже могла закоммититься в БД (транзакция per-row) —
   * если по ним успели накопиться результаты, сохраняем частичный отчёт вместо
   * того, чтобы молча его потерять.
   *
   * @param ImportRowResult[] $results
   */
  private function failWithPartialReport(string $file, array $results, string $message): int
  {
    $this->error($message);

    if ($results !== []) {
      $reportPath = $this->writeReport($file, $results);
      $this->warn("Импорт прерван, частичный отчёт: {$reportPath}");
    }

    return self::FAILURE;
  }

  /** Все значения строки после trim пусты — считаем строку полностью пустой (пропуск, не ошибка). */
  private function isBlankRow(array $values): bool
  {
    foreach ($values as $value) {
      if (trim($value) !== '') {
        return false;
      }
    }

    return true;
  }

  private function warnAboutMissingColumns(array $headers): void
  {
    $fillable = array_merge(
      array_keys(ProductContentSchema::PRODUCT_FIELDS),
      array_keys(ProductContentSchema::ATTRIBUTE_COLUMNS),
      [ProductContentSchema::COL_PHOTO_URLS],
    );

    $missing = array_diff($fillable, $headers);

    if ($missing !== []) {
      $this->warn('В файле нет заполняемых колонок (пропущены): ' . implode(', ', $missing));
    }
  }

  /** @param ImportRowResult[] $results */
  private function writeReport(string $file, array $results): string
  {
    $reportPath = preg_replace('/\.xlsx$/ui', '', $file) . '-report-' . now()->format('Ymd-His') . '.csv';

    $handle = fopen($reportPath, 'w');
    fwrite($handle, "\xEF\xBB\xBF"); // BOM — Excel открывает UTF-8 корректно
    fputcsv($handle, ['id', 'строка', 'поле', 'статус', 'причина'], ';');

    foreach ($results as $result) {
      foreach ($result->updated as $column) {
        fputcsv($handle, [$result->productId, $result->rowNumber, $column, 'updated', ''], ';');
      }
      foreach ($result->skipped as $column) {
        fputcsv($handle, [$result->productId, $result->rowNumber, $column, 'skipped_filled', 'уже заполнено'], ';');
      }
      foreach ($result->errors as $column => $reason) {
        fputcsv($handle, [$result->productId, $result->rowNumber, $column, 'error', $reason], ';');
      }
    }

    fclose($handle);

    return $reportPath;
  }

  /** @param ImportRowResult[] $results */
  private function printSummary(array $results, string $reportPath, bool $dryRun): void
  {
    $changedProducts = count(array_filter($results, fn (ImportRowResult $r) => $r->changed()));
    $fieldsWritten = array_sum(array_map(fn (ImportRowResult $r) => count($r->updated), $results));
    $fieldsSkipped = array_sum(array_map(fn (ImportRowResult $r) => count($r->skipped), $results));
    $errors = array_sum(array_map(fn (ImportRowResult $r) => count($r->errors), $results));

    if ($dryRun) {
      $this->warn('DRY-RUN: в БД ничего не записано.');
    }

    $this->table(['Метрика', 'Значение'], [
      ['Строк обработано', count($results)],
      [$dryRun ? 'Товаров будет обновлено' : 'Товаров обновлено', $changedProducts],
      [$dryRun ? 'Полей будет записано' : 'Полей записано', $fieldsWritten],
      ['Пропущено (уже заполнено)', $fieldsSkipped],
      ['Ошибок', $errors],
    ]);

    $this->info("Отчёт: {$reportPath}");
  }
}
