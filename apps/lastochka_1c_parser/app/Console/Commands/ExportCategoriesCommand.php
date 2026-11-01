<?php

namespace App\Console\Commands;

use App\Exports\CategoriesExport;
use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ExportCategoriesCommand extends Command
{
    protected $signature = 'categories:export
        {--active-only : Экспортировать только активные категории}
        {--output= : Имя выходного файла}';

    protected $description = 'Экспорт дерева категорий в Excel';

    public function handle(): int
    {
        $this->info('Начинаю экспорт категорий...');

        $activeOnly = $this->option('active-only');

        $export = new CategoriesExport($activeOnly);

        // Проверяем количество категорий
        $query = Category::query();
        if ($activeOnly) {
            $query->active();
        }
        $count = $query->count();

        if ($count === 0) {
            $this->warn('В базе нет категорий.');
            return self::SUCCESS;
        }

        $this->info("Найдено категорий: {$count}");

        // Формируем имя файла
        $filename = $this->option('output')
            ?: 'categories_' . now()->format('Y-m-d_H-i-s') . '.xlsx';

        if (!str_ends_with($filename, '.xlsx')) {
            $filename .= '.xlsx';
        }

        $exportPath = config('export.export_path', 'exports');
        $relativePath = "{$exportPath}/{$filename}";

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
                    ['Категорий экспортировано', $count],
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
