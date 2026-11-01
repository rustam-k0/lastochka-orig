<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Архивирование обработанного файла обмена вместо немедленного удаления.
 *
 * По умолчанию Job'ы импорта удаляли исходный XML сразу после обработки, из-за
 * чего реально пришедшую от 1С выгрузку нельзя было посмотреть постфактум.
 *
 * Трейт переносит обработанный файл в архив последней выгрузки
 * (storage/1cExchange/_last/) и вычищает оттуда файлы предыдущих выгрузок по
 * скользящему окну: при добавлении нового файла удаляются те, чьё время
 * изменения старше (mtime нового − окно). Окно больше длительности одной пачки
 * и меньше интервала между выгрузками, поэтому в архиве всегда лежит ровно
 * последняя выгрузка — пока её не вытеснит следующая.
 *
 * Использующий класс обязан иметь свойство string $this->filePath.
 */
trait ArchivesProcessedFile
{
    /**
     * Очистка временного файла после обработки: архивирование либо (если
     * фича выключена) удаление. Сохраняет прежнее имя метода, чтобы вызовы
     * в Job'ах не менялись.
     */
    protected function cleanupFile(): void
    {
        if (!str_contains($this->filePath, '1cExchange') || !file_exists($this->filePath)) {
            return;
        }

        if (!config('exchange1c.keep_last_exchange', true)) {
            @unlink($this->filePath);

            return;
        }

        $this->archiveProcessedFile($this->filePath);
    }

    /**
     * Переносит обработанный файл в архив последней выгрузки и вычищает из
     * архива файлы предыдущих выгрузок. При любой ошибке откатывается к
     * удалению, чтобы не накапливать необработанный мусор.
     */
    protected function archiveProcessedFile(string $filePath): void
    {
        try {
            $archiveDir = storage_path('1cExchange/_last');

            if (!is_dir($archiveDir) && !@mkdir($archiveDir, 0775, true) && !is_dir($archiveDir)) {
                @unlink($filePath);

                return;
            }

            // Имя папки сессии в префиксе: файлы одной выгрузки не перетирают
            // друг друга и сразу видно, к какой сессии относится файл.
            $session = basename(dirname($filePath));
            $target = $archiveDir . '/' . $session . '__' . basename($filePath);

            if (!@rename($filePath, $target)) {
                // rename между разными файловыми системами может не сработать.
                if (@copy($filePath, $target)) {
                    @unlink($filePath);
                } else {
                    @unlink($filePath);

                    return;
                }
            }

            $this->pruneArchive($archiveDir, $target);
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->warning('archiveProcessedFile: не смог заархивировать файл', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            @unlink($filePath);
        }
    }

    /**
     * Удаляет из архива файлы старше скользящего окна относительно mtime
     * только что добавленного файла — то есть файлы предыдущих выгрузок.
     */
    protected function pruneArchive(string $archiveDir, string $justAdded): void
    {
        $windowMinutes = (int) config('exchange1c.keep_last_window_minutes', 10);
        $referenceMtime = @filemtime($justAdded) ?: time();
        $cutoff = $referenceMtime - $windowMinutes * 60;

        foreach (glob($archiveDir . '/*') ?: [] as $file) {
            if ($file === $justAdded || !is_file($file)) {
                continue;
            }

            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }
}
