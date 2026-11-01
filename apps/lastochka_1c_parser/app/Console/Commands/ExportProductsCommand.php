<?php

namespace App\Console\Commands;

use App\Exports\ProductsExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ExportProductsCommand extends Command
{
    protected $signature = 'products:export
        {--active-only : Экспортировать только активные товары}
        {--in-stock : Экспортировать только товары в наличии}
        {--limit= : Максимальное количество товаров для экспорта}
        {--output= : Имя выходного файла}';

    protected $description = 'Экспорт товаров в Excel для интеграции с маркетплейсами';

    public function handle(): int
    {
        // Увеличиваем лимит памяти для обработки большого количества товаров
        ini_set('memory_limit', '1G');

        $this->info('Начинаю экспорт товаров...');

        $activeOnly = $this->option('active-only');
        $inStockOnly = $this->option('in-stock');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $export = new ProductsExport($activeOnly, $inStockOnly, $limit);

        // Проверяем количество товаров
        $count = $export->query()->count();

        if ($count === 0) {
            $this->warn('В базе нет товаров, соответствующих критериям.');
            $this->info('Создаю пустой файл с заголовками...');
        } else {
            $this->info("Найдено товаров: {$count}");
        }

        // Формируем имя файла
        $filename = $this->option('output')
            ?: 'products_' . now()->format('Y-m-d_H-i-s') . '.xlsx';

        // Убедимся что файл имеет расширение xlsx
        if (!str_ends_with($filename, '.xlsx')) {
            $filename .= '.xlsx';
        }

        $exportPath = config('export.export_path', 'exports');
        $relativePath = "{$exportPath}/{$filename}";

        // Убедимся что директория существует
        Storage::makeDirectory($exportPath);

        try {
            $this->info('Генерирую Excel файл...');

            Excel::store($export, $relativePath);

            $fullPath = Storage::path($relativePath);

            $this->newLine();
            $this->info('✅ Экспорт завершён успешно!');
            $this->table(
                ['Параметр', 'Значение'],
                [
                    ['Товаров экспортировано', $count],
                    ['Файл', $fullPath],
                    ['Размер', $this->formatFileSize(Storage::size($relativePath))],
                ]
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('❌ Ошибка при экспорте: ' . $e->getMessage());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * Форматировать размер файла
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 2) . ' ' . $units[$index];
    }
}
