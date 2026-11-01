<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Images\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Разовый (и повторяемый) перегон уже сохранённых картинок витрины в WebP.
 *
 * Что делает и, главное, чего НЕ делает:
 * - создаёт файл рядом со старым, оригинал не удаляет;
 * - меняет в БД только колонку с путём, source_url не трогает — иначе
 *   ProductEventTransformer перестанет узнавать картинку и начнёт качать заново;
 * - пишет манифест старый→новый путь, по которому `--rollback` возвращает всё назад.
 *
 * Команда идемпотентна: пути, которые уже ведут на .webp, пропускаются,
 * поэтому прогон можно останавливать и продолжать.
 */
final class OptimizeStoredImages extends Command
{
    protected $signature = 'images:optimize
        {--dry-run : Только посчитать, ничего не менять}
        {--only= : Список таблиц через запятую (по умолчанию все из config)}
        {--limit=0 : Обработать не больше N картинок (0 — без ограничения)}
        {--chunk=200 : Размер порции при обходе таблицы}
        {--quality= : Переопределить качество WebP}
        {--max-width= : Переопределить ограничение по ширине}
        {--rollback= : Путь к манифесту: вернуть пути в БД на прежние значения}';

    protected $description = 'Перевести сохранённые картинки в WebP с ограничением по ширине';

    private int $processed = 0;

    private int $skipped = 0;

    private int $failed = 0;

    private int $bytesBefore = 0;

    private int $bytesAfter = 0;

    /** @var resource|null */
    private $manifest = null;

    public function handle(): int
    {
        if (($manifestPath = (string) $this->option('rollback')) !== '') {
            return $this->rollback($manifestPath);
        }

        $optimizer = $this->buildOptimizer();
        $targets = $this->resolveTargets();

        if ($targets === []) {
            $this->error('Не выбрано ни одной таблицы для обработки.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if (! $dryRun) {
            $this->openManifest();
        }

        $this->info($dryRun
            ? 'Холостой прогон: файлы и БД не меняются.'
            : 'Боевой прогон. Оригиналы остаются на диске, откат — по манифесту.');

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

                $this->processColumn($optimizer, $table, $column, $dryRun, $limit);

                if ($limit > 0 && $this->processed >= $limit) {
                    break 2;
                }
            }
        }

        $this->closeManifest();
        $this->report($dryRun);

        return self::SUCCESS;
    }

    private function buildOptimizer(): ImageOptimizer
    {
        if (($quality = $this->option('quality')) !== null && $quality !== '') {
            config(['images.optimize.quality' => (int) $quality]);
        }

        if (($maxWidth = $this->option('max-width')) !== null && $maxWidth !== '') {
            config(['images.optimize.max_width' => (int) $maxWidth]);
        }

        // Бэкфилл должен работать и когда оптимизация на приёме выключена.
        config(['images.optimize.enabled' => true]);

        return ImageOptimizer::fromConfig();
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

    private function processColumn(
        ImageOptimizer $optimizer,
        string $table,
        string $column,
        bool $dryRun,
        int $limit,
    ): void {
        $disk = Storage::disk('public');
        $hasMime = Schema::hasColumn($table, 'mime_type');
        $this->line("  {$table}.{$column}");

        DB::table($table)
            ->select($hasMime ? ['id', $column, 'mime_type'] : ['id', $column])
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, 'not like', '%.webp')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($rows) use (
                $optimizer, $table, $column, $dryRun, $limit, $disk, $hasMime
            ) {
                foreach ($rows as $row) {
                    if ($limit > 0 && $this->processed >= $limit) {
                        return false;
                    }

                    $oldPath = (string) $row->{$column};
                    $extension = mb_strtolower(pathinfo($oldPath, PATHINFO_EXTENSION));

                    if (! $optimizer->handles($extension)) {
                        $this->skipped++;

                        continue;
                    }

                    if (! $disk->exists($oldPath)) {
                        // Строка в БД есть, файла нет — не наша забота, но и не ошибка.
                        $this->skipped++;

                        continue;
                    }

                    $binary = (string) $disk->get($oldPath);
                    $optimized = $optimizer->optimize($binary, $extension);

                    if ($optimized === null) {
                        // Не стало меньше или формат не поддался — оставляем как есть.
                        $this->skipped++;

                        continue;
                    }

                    $newPath = $this->targetPath($oldPath);

                    if ($dryRun) {
                        $this->countSaving(strlen($binary), $optimized->size());

                        continue;
                    }

                    try {
                        $disk->put($newPath, $optimized->binary);

                        $update = [$column => $newPath];
                        $oldMime = null;
                        if ($hasMime) {
                            $oldMime = $row->mime_type ?? null;
                            $update['mime_type'] = $optimized->mimeType;
                        }

                        DB::table($table)->where('id', $row->id)->update($update);
                        $this->writeManifest($table, $column, (int) $row->id, $oldPath, $newPath, $oldMime);
                        $this->countSaving(strlen($binary), $optimized->size());
                    } catch (\Throwable $e) {
                        // Байты не засчитываем: экономии не случилось.
                        $this->failed++;
                        $this->warn("    {$table}#{$row->id}: {$e->getMessage()}");
                    }
                }

                return true;
            }, 'id');
    }

    private function countSaving(int $before, int $after): void
    {
        $this->bytesBefore += $before;
        $this->bytesAfter += $after;
        $this->processed++;
    }

    /**
     * Имя сохраняем, меняем только расширение: так связь оригинала
     * и производной копии остаётся очевидной при разборе инцидентов.
     */
    private function targetPath(string $oldPath): string
    {
        $dir = pathinfo($oldPath, PATHINFO_DIRNAME);
        $name = pathinfo($oldPath, PATHINFO_FILENAME);

        return ($dir === '.' ? '' : $dir.'/').$name.'.webp';
    }

    private function openManifest(): void
    {
        $relative = 'image-optimize/'.now()->format('Ymd-His').'.jsonl';
        Storage::disk('local')->put($relative, '');
        $absolute = Storage::disk('local')->path($relative);

        $handle = fopen($absolute, 'ab');
        $this->manifest = $handle === false ? null : $handle;

        $this->info("  манифест отката: {$absolute}");
    }

    private function writeManifest(
        string $table,
        string $column,
        int $id,
        string $old,
        string $new,
        ?string $oldMime = null,
    ): void {
        if ($this->manifest === null) {
            return;
        }

        fwrite($this->manifest, json_encode(
            [
                'table' => $table,
                'column' => $column,
                'id' => $id,
                'old' => $old,
                'new' => $new,
                'old_mime' => $oldMime,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ).PHP_EOL);
    }

    private function closeManifest(): void
    {
        if ($this->manifest !== null) {
            fclose($this->manifest);
            $this->manifest = null;
        }
    }

    private function rollback(string $path): int
    {
        if (! is_readable($path)) {
            $this->error("Манифест не найден: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error('Не удалось открыть манифест.');

            return self::FAILURE;
        }

        $restored = 0;
        $missing = 0;
        $disk = Storage::disk('public');

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            /** @var array{table: string, column: string, id: int, old: string, new: string}|null $entry */
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                continue;
            }

            if (! $disk->exists($entry['old'])) {
                // Оригинал удалили — возвращать путь некуда, иначе получим битую картинку.
                $missing++;
                $this->warn("  оригинал отсутствует, пропускаю: {$entry['old']}");

                continue;
            }

            $restore = [$entry['column'] => $entry['old']];
            if (($entry['old_mime'] ?? null) !== null) {
                $restore['mime_type'] = $entry['old_mime'];
            }

            DB::table($entry['table'])
                ->where('id', $entry['id'])
                ->update($restore);

            $restored++;
        }

        fclose($handle);

        $this->info("Откат завершён: возвращено {$restored}, пропущено {$missing}.");
        $this->line('Файлы .webp не удалялись — при необходимости уберите их отдельно.');

        return self::SUCCESS;
    }

    private function report(bool $dryRun): void
    {
        $saved = $this->bytesBefore - $this->bytesAfter;
        $percent = $this->bytesBefore > 0 ? $saved * 100 / $this->bytesBefore : 0.0;

        $this->newLine();
        $this->table(['показатель', 'значение'], [
            ['обработано', (string) $this->processed],
            ['пропущено', (string) $this->skipped],
            ['ошибок', (string) $this->failed],
            ['было', $this->humanBytes($this->bytesBefore)],
            ['стало', $this->humanBytes($this->bytesAfter)],
            ['экономия', sprintf('%s (%.1f%%)', $this->humanBytes($saved), $percent)],
        ]);

        if ($dryRun) {
            $this->comment('Это был холостой прогон, ничего не изменено.');
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f МБ', $bytes / 1048576);
        }

        return sprintf('%.1f КБ', $bytes / 1024);
    }
}
