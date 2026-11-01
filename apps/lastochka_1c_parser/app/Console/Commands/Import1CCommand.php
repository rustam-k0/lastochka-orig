<?php

namespace App\Console\Commands;

use App\Models\ExchangeLog;
use App\Services\Exchange1C\CatalogImporter;
use App\Services\Exchange1C\OffersImporter;
use Illuminate\Console\Command;

/**
 * Ручной запуск импорта из 1С (для отладки)
 *
 * php artisan 1c:import --type=catalog
 * php artisan 1c:import --type=offers
 * php artisan 1c:import --type=catalog --file=/path/to/import.xml
 */
class Import1CCommand extends Command
{
    protected $signature = '1c:import
                            {--type=catalog : Тип импорта (catalog, offers)}
                            {--file= : Путь к XML файлу (опционально)}';

    protected $description = 'Ручной запуск импорта из 1С';

    public function handle(): int
    {
        $type = $this->option('type');
        $filePath = $this->option('file');

        $this->info("Запуск импорта: {$type}");

        if (!$filePath) {
            // Ищем последний файл в папке обмена
            $filePath = $this->findLatestFile($type);

            if (!$filePath) {
                $this->error('Файл для импорта не найден. Укажите путь через --file=');
                return self::FAILURE;
            }
        }

        if (!file_exists($filePath)) {
            $this->error("Файл не найден: {$filePath}");
            return self::FAILURE;
        }

        $this->info("Обработка файла: {$filePath}");

        $log = ExchangeLog::start(
            $type === 'catalog'
                ? ExchangeLog::TYPE_CATALOG_IMPORT
                : ExchangeLog::TYPE_OFFERS_IMPORT
        );

        try {
            if ($type === 'catalog') {
                $importer = app(CatalogImporter::class);
            } else {
                $importer = app(OffersImporter::class);
            }

            $result = $importer->import($filePath, $log);

            $this->info('Импорт завершен успешно!');
            $this->table(
                ['Параметр', 'Значение'],
                collect($result)->except('warnings')->map(fn($v, $k) => [$k, $v])->values()->toArray()
            );

            if (!empty($result['warnings'])) {
                $this->warn('Предупреждения:');
                foreach ($result['warnings'] as $warning) {
                    $this->line("  - {$warning}");
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Ошибка: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Поиск последнего файла в папке обмена
     */
    protected function findLatestFile(string $type): ?string
    {
        $exchangePath = storage_path('1cExchange');

        if (!is_dir($exchangePath)) {
            return null;
        }

        // 1С может присылать как offers*.xml, так и prices___*.xml
        $pattern = $type === 'catalog' ? 'import*.xml' : '{offers,prices}*.xml';
        $files = glob($exchangePath . '/' . $pattern, GLOB_BRACE);

        if (empty($files)) {
            // Попробуем рекурсивно
            $files = glob($exchangePath . '/**/' . $pattern, GLOB_BRACE);
        }

        if (empty($files)) {
            return null;
        }

        // Сортируем по времени изменения
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        return $files[0];
    }
}
