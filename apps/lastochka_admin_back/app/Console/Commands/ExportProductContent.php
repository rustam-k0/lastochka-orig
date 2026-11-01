<?php

namespace App\Console\Commands;

use App\Services\ProductContent\ProductContentExportService;
use App\Services\ProductContent\ProductContentSchema;
use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Выгрузка всей номенклатуры товаров в XLSX для заполнения парсером
 * (LASTOCHKI-JAMI-12027). Лист «Товары» + лист «Инструкция».
 */
class ExportProductContent extends Command
{
  protected $signature = 'products:export-content
    {--only-unfilled : Только товары, где не хватает контента (описание/атрибуты/фото)}
    {--path= : Путь итогового XLSX (по умолчанию storage/app/exports/...)}';

  protected $description = 'Выгрузка номенклатуры товаров в XLSX для заполнения парсером (LASTOCHKI-JAMI-12027)';

  public function handle(ProductContentExportService $service): int
  {
    $path = $this->option('path')
      ?: storage_path('app/exports/product-content-' . now()->format('Ymd-His') . '.xlsx');

    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0775, true);
    }

    $writer = new Writer();
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Товары');

    $writer->addRow(Row::fromValues(ProductContentSchema::HEADERS));

    $count = 0;
    foreach ($service->rows((bool) $this->option('only-unfilled')) as $row) {
      $writer->addRow(Row::fromValues($row));
      $count++;
    }

    $instructionSheet = $writer->addNewSheetAndMakeItCurrent();
    $instructionSheet->setName('Инструкция');
    foreach (ProductContentSchema::instructionRows() as $row) {
      $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    $this->info("Выгружено товаров: {$count}");
    $this->info("Файл: {$path}");

    return self::SUCCESS;
  }
}
