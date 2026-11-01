<?php

namespace App\Console\Commands;

use App\Models\CatalogImportChunk;
use App\Models\ExchangeLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

/**
 * Очистка старых файлов обмена и логов
 *
 * php artisan 1c:cleanup --days=7
 */
class Cleanup1CCommand extends Command
{
    /** Пауза между батчами, чтобы обмен 1С успевал взять блокировки на вставку. */
    private const BATCH_PAUSE_MICROSECONDS = 200000;

    protected $signature = '1c:cleanup
                            {--days=7 : Удалять файлы старше N дней}
                            {--logs : Также очистить старые записи exchange_logs}
                            {--chunk=20000 : Записей логов за один DELETE (0 отключает батчинг)}';

    protected $description = 'Очистка старых файлов обмена с 1С';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cleanLogs = $this->option('logs');
        $cutoff = Carbon::now()->subDays($days);

        $this->info("Очистка файлов старше {$days} дней...");

        [$filesDeleted, $filesFailed] = $this->cleanupFiles($cutoff);
        $this->info("Удалено файлов: {$filesDeleted}");
        if ($filesFailed > 0) {
            $this->warn("Не удалось удалить файлов: {$filesFailed} (см. канал 1c-exchange)");
        }

        if ($cleanLogs) {
            $logsDeleted = $this->cleanupLogs($cutoff);
            $this->info("Удалено записей логов: {$logsDeleted}");

            $chunksDeleted = $this->cleanupChunks($cutoff);
            $this->info("Удалено записей состава выгрузки: {$chunksDeleted}");
        }

        return self::SUCCESS;
    }

    /**
     * Очистка файлов
     *
     * @return array{0: int, 1: int} [deleted, failed]
     */
    protected function cleanupFiles(Carbon $cutoff): array
    {
        $exchangePath = storage_path('1cExchange');
        $exportPath = storage_path('app/1c-export');

        $deleted = 0;
        $failed = 0;

        foreach ([$exchangePath, $exportPath] as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = $this->safeAllFiles($path);

            foreach ($files as $file) {
                // mtime читаем через @filemtime, а не через SplFileInfo::getMTime().
                // Список файлов — снимок на момент обхода, а каталог 1cExchange/_last
                // параллельно перетряхивает ArchivesProcessedFile::pruneArchive: файл
                // успевает исчезнуть между сканом и чтением, и getMTime() бросает
                // RuntimeException «stat failed», роняя всю очистку (а вместе с ней —
                // и ночной schedule).
                // Отдельная переменная: $path — это каталог из внешнего цикла,
                // и он нужен ниже для cleanupEmptyDirs().
                $filePath = $file->getPathname();
                $mtime = @filemtime($filePath);

                if ($mtime === false) {
                    continue;
                }

                if (Carbon::createFromTimestamp($mtime)->lt($cutoff)) {
                    if (File::delete($filePath)) {
                        $deleted++;
                    } elseif (file_exists($filePath)) {
                        $failed++;
                        $this->logFailure($filePath);
                    }
                }
            }

            $this->cleanupEmptyDirs($path);
        }

        return [$deleted, $failed];
    }

    /**
     * Логирование неудалённого файла с владельцем — позволяет диагностировать
     * permission-issues (когда cron работает не под тем пользователем).
     */
    protected function logFailure(string $path): void
    {
        $owner = function_exists('posix_getpwuid') && file_exists($path)
            ? (posix_getpwuid(fileowner($path))['name'] ?? 'unknown')
            : 'unknown';

        Log::channel('1c-exchange')->warning('1c:cleanup не смог удалить файл', [
            'path' => $path,
            'owner' => $owner,
        ]);
    }

    /**
     * Удаление пустых директорий
     */
    protected function cleanupEmptyDirs(string $path): void
    {
        foreach ($this->safeDirectories($path) as $dir) {
            $this->cleanupEmptyDirs($dir);

            // rmdir сам отказывается удалять непустой каталог, поэтому проверять
            // содержимое заранее не нужно. Прежняя версия звала на КАЖДЫЙ каталог
            // рекурсивный обход Finder'ом (safeAllFiles + safeDirectories): на
            // проде Ласточки в 1cExchange/djami_store накопилось 683 тыс. пустых
            // каталогов сессий обмена, и очистка до них просто не доходила.
            // Ошибку (каталог непуст или уже исчез) гасим — идём дальше.
            @rmdir($dir);
        }
    }

    /**
     * Обход каталога, устойчивый к его исчезновению на лету.
     *
     * Каталоги сессий обмена (storage/1cExchange/<shop>/<timestamp>_<hash>/)
     * живут ровно до конца обработки выгрузки: их вычищает сам обмен, пока
     * очистка идёт по соседним. Symfony Finder на пропавшем пути бросает
     * DirectoryNotFoundException и роняет всю команду вместе с ночным
     * schedule — поэтому исчезнувший каталог считаем пустым и идём дальше.
     *
     * @return array<int, \SplFileInfo>
     */
    protected function safeAllFiles(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        try {
            return File::allFiles($path);
        } catch (DirectoryNotFoundException $e) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    protected function safeDirectories(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        try {
            return File::directories($path);
        } catch (DirectoryNotFoundException $e) {
            return [];
        }
    }

    /**
     * Очистка устаревших записей состава выгрузки каталога.
     *
     * В норме чанки живут минуты: шаг deactivate потребляет их сразу после
     * полной выгрузки. Копятся они только если чекбокс удаления выключили
     * после записи чанков — тогда потребить их некому.
     */
    protected function cleanupChunks(Carbon $cutoff): int
    {
        return CatalogImportChunk::where('created_at', '<', $cutoff)->delete();
    }

    /**
     * Очистка старых логов обмена
     */
    protected function cleanupLogs(Carbon $cutoff): int
    {
        $chunk = (int) $this->option('chunk');

        // Одиночный DELETE на всём объёме держит блокировки и останавливает
        // запись обмена: на проде Ласточки в exchange_logs 862 тыс. строк при
        // ~70 вставках в минуту от 1С, а под удаление уходило до 376 тыс. за раз.
        // Поэтому удаляем батчами, отдавая блокировки между ними.
        if ($chunk <= 0) {
            return ExchangeLog::where('created_at', '<', $cutoff)->delete();
        }

        $deleted = 0;

        do {
            $affected = ExchangeLog::where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $affected;

            if ($affected > 0) {
                usleep(self::BATCH_PAUSE_MICROSECONDS);
            }
        } while ($affected > 0);

        return $deleted;
    }
}
