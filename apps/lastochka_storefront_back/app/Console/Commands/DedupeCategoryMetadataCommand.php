<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Схлопывает дубликаты в `event_sync_metadata` для entity_type='categories'
 * (ALADDIN-9822).
 *
 * Накопились исторически: до включения global_scope=true пакет создавал
 * по одной записи на каждый (source, external_id, shop_code). После
 * включения global_scope=true consumer ищет без shop_code, находит первую
 * попавшуюся и пытается переписать её shop_code на пришедший — ловит
 * unique violation на оставшихся дубликатах → DLQ → pivot category_store
 * не пополняется/не очищается.
 *
 * Команда оставляет в каждой группе (entity_type, source, external_id)
 * одну каноничную запись (с самым свежим last_event_timestamp, при равенстве —
 * с максимальным id), остальные удаляет.
 *
 * Запуск:
 *   php artisan categories:dedupe-metadata               # dry-run
 *   php artisan categories:dedupe-metadata --apply       # выполнить
 *
 * Перед `--apply` на prod — обязательный mysqldump таблицы
 * event_sync_metadata в /home/admin/backups/.
 */
final class DedupeCategoryMetadataCommand extends Command
{
    protected $signature = 'categories:dedupe-metadata
                            {--apply : Выполнить удаление; без флага — dry-run}';

    protected $description = 'Схлопывает дубликаты event_sync_metadata для категорий (ALADDIN-9822)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $groups = DB::table('event_sync_metadata')
            ->select('source', 'external_id', DB::raw('COUNT(*) AS cnt'))
            ->where('entity_type', 'categories')
            ->groupBy('source', 'external_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($groups->isEmpty()) {
            $this->info('Дубликатов не найдено.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Найдено групп с дубликатами: %d', $groups->count()));

        $totalToDelete = 0;
        $idsToDelete = [];

        foreach ($groups as $group) {
            $rows = DB::table('event_sync_metadata')
                ->where('entity_type', 'categories')
                ->where('source', $group->source)
                ->where('external_id', $group->external_id)
                ->orderByRaw('last_event_timestamp DESC, id DESC')
                ->get();

            $keep = $rows->shift();
            $toDelete = $rows;

            $entityIds = $rows->pluck('entity_id')->push($keep->entity_id)->unique()->values()->all();
            $entityIdsLabel = count($entityIds) === 1 ? (string) $entityIds[0] : 'MIXED:'.implode(',', $entityIds);

            $this->line(sprintf(
                '(source=%s, external_id=%s, entity_id=%s) keep id=%d shop_code=%s ts=%s',
                $group->source,
                $group->external_id,
                $entityIdsLabel,
                $keep->id,
                $keep->shop_code ?? 'NULL',
                $keep->last_event_timestamp ?? 'NULL'
            ));

            foreach ($toDelete as $row) {
                $this->line(sprintf(
                    '  - DELETE id=%d shop_code=%s ts=%s%s',
                    $row->id,
                    $row->shop_code ?? 'NULL',
                    $row->last_event_timestamp ?? 'NULL',
                    $row->entity_id !== $keep->entity_id ? ' [MIXED entity_id]' : ''
                ));
                $idsToDelete[] = $row->id;
                $totalToDelete++;
            }
        }

        if (! $apply) {
            $this->warn(sprintf('DRY-RUN. К удалению: %d строк. Запусти с --apply.', $totalToDelete));

            return self::SUCCESS;
        }

        $deleted = DB::table('event_sync_metadata')->whereIn('id', $idsToDelete)->delete();
        $this->info(sprintf('Удалено: %d строк.', $deleted));

        return self::SUCCESS;
    }
}
