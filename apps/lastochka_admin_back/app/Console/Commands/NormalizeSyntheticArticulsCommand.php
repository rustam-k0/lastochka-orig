<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Возврат оригинальных артикулов товарам, получившим синтетический
 * `1C-{sku}-{hash8}[-counter]` из-за старой ГЛОБАЛЬНОЙ проверки коллизии sku
 * в ProductEventTransformer::resolveUniqueArticul (до скоупинга по магазину
 * весь ассортимент каждого магазина после первого помечался «коллизией»).
 *
 * Переименование допускается только когда оно безопасно относительно
 * DB-инварианта UNIQUE(shop_id, articul):
 *  - если в том же магазине НЕСКОЛЬКО синтетических карточек сводятся к одному
 *    оригинальному sku — это настоящий внутримагазинный дубль из 1С, вся группа
 *    остаётся синтетической (ровно для этого механизм и писался);
 *  - если оригинальный sku уже занят другой карточкой магазина — пропуск.
 *
 * Обновление НАМЕРЕННО тихое (DB::table, без Eloquent-событий и outbox):
 * массовая рассылка сотен тысяч product-событий утопила бы очереди во время
 * идущей выгрузки. Storefront синхронизации не требует: его beforeCreate был
 * скоуплен по store с самого начала, оригинальные sku там уже лежат, а
 * update-flow обеих сторон articul/sku не перезаписывает.
 */
class NormalizeSyntheticArticulsCommand extends Command
{
    protected $signature = 'products:normalize-synthetic-articuls
        {--dry-run : Только посчитать и показать план, ничего не менять}
        {--chunk=2000 : Размер чанка UPDATE}';

    protected $description = 'Вернуть оригинальные артикулы товарам с синтетическим 1C-{sku}-{hash} там, где это не нарушает UNIQUE(shop_id, articul)';

    private const SYNTHETIC_PATTERN = '/^1C-(?<sku>.+)-(?<hash>[A-Za-z0-9]{8})(?:-(?<counter>\d{1,3}))?$/';

    public function handle(): int
    {
        $stats = [
            'candidates' => 0,
            'renamed' => 0,
            'skipped_dup_group' => 0,
            'skipped_occupied' => 0,
            'unparseable' => 0,
        ];

        // id → оригинальный sku; группировка по (shop_id, sku) для отсева дублей.
        $renames = [];
        /** @var array<string, list<int>> $groups ключ "shop_id|sku" */
        $groups = [];

        DB::table('products')
            ->select('id', 'shop_id', 'articul')
            ->where('articul', 'LIKE', '1C-%')
            ->chunkById(10000, function ($rows) use (&$stats, &$renames, &$groups) {
                foreach ($rows as $row) {
                    $stats['candidates']++;

                    if (!preg_match(self::SYNTHETIC_PATTERN, (string) $row->articul, $m)) {
                        $stats['unparseable']++;
                        continue;
                    }

                    $key = ($row->shop_id ?? 'null') . '|' . $m['sku'];
                    $groups[$key][] = (int) $row->id;
                    $renames[(int) $row->id] = ['shop_id' => $row->shop_id, 'sku' => $m['sku']];
                }
            });

        // Настоящие внутримагазинные дубли: >1 кандидата на один (shop_id, sku).
        foreach ($groups as $ids) {
            if (count($ids) > 1) {
                $stats['skipped_dup_group'] += count($ids);
                foreach ($ids as $id) {
                    unset($renames[$id]);
                }
            }
        }

        // Оригинальный sku уже занят другой карточкой того же магазина
        // (включая soft-deleted: UNIQUE-индекс их тоже видит).
        $byShop = [];
        $idByShopSku = [];
        foreach ($renames as $id => $target) {
            $shopKey = $target['shop_id'] ?? 'null';
            $byShop[$shopKey][$target['sku']] = true;
            $idByShopSku[$shopKey . '|' . $target['sku']] = $id;
        }

        foreach ($byShop as $shopKey => $skus) {
            $shopId = $shopKey === 'null' ? null : (int) $shopKey;

            foreach (array_chunk(array_keys($skus), 5000) as $skuChunk) {
                $occupied = DB::table('products')
                    ->when(
                        $shopId === null,
                        fn ($q) => $q->whereNull('shop_id'),
                        fn ($q) => $q->where('shop_id', $shopId)
                    )
                    ->whereIn('articul', $skuChunk)
                    ->pluck('articul');

                foreach ($occupied as $sku) {
                    $id = $idByShopSku[$shopKey . '|' . $sku] ?? null;
                    if ($id !== null && isset($renames[$id])) {
                        unset($renames[$id]);
                        $stats['skipped_occupied']++;
                    }
                }
            }
        }

        $this->info(sprintf(
            'Кандидатов: %d, к переименованию: %d, дубль-группы: %d, занятый sku: %d, нераспознанных: %d',
            $stats['candidates'],
            count($renames),
            $stats['skipped_dup_group'],
            $stats['skipped_occupied'],
            $stats['unparseable']
        ));

        if ($this->option('dry-run')) {
            foreach (array_slice($renames, 0, 10, true) as $id => $target) {
                $this->line("  #{$id}: → {$target['sku']} (shop {$target['shop_id']})");
            }
            $this->warn('Dry-run: изменения не применены.');

            return self::SUCCESS;
        }

        $chunkSize = max(100, (int) $this->option('chunk'));
        $bar = $this->output->createProgressBar(count($renames));

        foreach (array_chunk($renames, $chunkSize, true) as $chunk) {
            DB::transaction(function () use ($chunk, &$stats, $bar) {
                foreach ($chunk as $id => $target) {
                    DB::table('products')
                        ->where('id', $id)
                        ->update(['articul' => $target['sku']]);
                    $stats['renamed']++;
                    $bar->advance();
                }
            });
        }

        $bar->finish();
        $this->newLine();
        $this->info("Переименовано: {$stats['renamed']}");

        return self::SUCCESS;
    }
}
