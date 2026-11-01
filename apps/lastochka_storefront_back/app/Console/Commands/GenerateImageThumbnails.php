<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Images\ImageThumbnails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Бэкфилл превью для сетки каталога по уже сохранённым картинкам.
 *
 * Новые картинки получают превью при скачивании (BaseEventTransformer::downloadImage),
 * эта команда догоняет то, что лежало на диске до внедрения. БД не меняет:
 * путь превью вычисляется из пути картинки, API начинает отдавать его в
 * thumbPath, как только файл появился на диске.
 *
 * Идемпотентна: существующие превью пропускаются (кроме --force), прогон
 * можно останавливать и продолжать.
 */
final class GenerateImageThumbnails extends Command
{
    protected $signature = 'images:thumbnails
        {--dry-run : Только посчитать, ничего не создавать}
        {--only= : Список таблиц через запятую (по умолчанию все из images.optimize.backfill_targets)}
        {--limit=0 : Создать не больше N превью (0 — без ограничения)}
        {--chunk=200 : Размер порции при обходе таблицы}
        {--force : Пересоздать превью, даже если оно уже есть}';

    protected $description = 'Создать превью ~300 px (WebP) для сохранённых картинок витрины';

    private int $created = 0;

    private int $existing = 0;

    private int $skipped = 0;

    private int $failed = 0;

    private int $bytes = 0;

    public function handle(): int
    {
        // Бэкфилл должен работать и когда генерация на приёме выключена.
        config(['images.thumbnails.enabled' => true]);

        $targets = $this->resolveTargets();

        if ($targets === []) {
            $this->error('Не выбрано ни одной таблицы для обработки.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Холостой прогон: файлы не создаются.');
        }

        foreach ($targets as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->line("  {$table}: таблицы нет, пропускаю");

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $this->line("  {$table}.{$column}: колонки нет, пропускаю");

                    continue;
                }

                if (! $this->processColumn($table, $column, $dryRun)) {
                    break 2;
                }
            }
        }

        $this->newLine();
        $this->table(['показатель', 'значение'], [
            [$dryRun ? 'будет создано' : 'создано', (string) $this->created],
            ['уже были', (string) $this->existing],
            ['пропущено', (string) $this->skipped],
            ['ошибок', (string) $this->failed],
            ['вес превью', sprintf('%.1f КБ', $this->bytes / 1024)],
        ]);

        if ($this->failed > 0) {
            $this->warn('Часть превью не записана. Частая причина: команда запущена не под тем пользователем,');
            $this->warn('под которым работает консьюмер RabbitMQ (он тоже пишет в каталоги thumbs). Запускайте под ним.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<string, list<string>> */
    private function resolveTargets(): array
    {
        /** @var array<string, list<string>> $all */
        $all = (array) config('images.optimize.backfill_targets', []);

        $only = (string) $this->option('only');
        if ($only === '') {
            return $all;
        }

        $wanted = array_filter(array_map('trim', explode(',', $only)));

        return array_intersect_key($all, array_flip($wanted));
    }

    /**
     * @return bool false — достигнут --limit, обход надо остановить
     */
    private function processColumn(string $table, string $column, bool $dryRun): bool
    {
        $disk = Storage::disk('public');
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');
        $limitReached = false;

        $this->line("  {$table}.{$column}");

        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($rows) use (
                $column, $dryRun, $disk, $limit, $force, &$limitReached
            ) {
                foreach ($rows as $row) {
                    if ($limit > 0 && $this->created >= $limit) {
                        $limitReached = true;

                        return false;
                    }

                    $path = (string) $row->{$column};
                    $thumbPath = ImageThumbnails::pathFor($path);

                    if ($thumbPath === null || ! $disk->exists($path)) {
                        $this->skipped++;

                        continue;
                    }

                    if (! $force && $disk->exists($thumbPath)) {
                        $this->existing++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->created++;

                        continue;
                    }

                    try {
                        $generated = ImageThumbnails::generateOrFail($path);
                    } catch (\Throwable $e) {
                        $this->failed++;
                        $this->warn("    {$path}: {$e->getMessage()}");

                        continue;
                    }

                    if ($generated === null) {
                        // svg/gif/видео или превью не стало меньше оригинала.
                        $this->skipped++;

                        continue;
                    }

                    $this->created++;
                    $this->bytes += (int) $disk->size($thumbPath);
                }

                return true;
            }, 'id');

        return ! $limitReached;
    }
}
