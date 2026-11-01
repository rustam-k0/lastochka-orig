<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category1c;
use App\Models\Shop;
use App\Services\ModuleConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Однократная нормализация пер-магазинных категорий 1С к якорному магазину
 * при включении сетевого режима маппинга.
 *
 * Для каждой строки categories_1c с shop_id != якоря:
 *  - дубликата (по external_id) под якорем нет → строка переносится (UPDATE shop_id);
 *  - дубликат есть → merge: pivot-привязки переносятся аддитивно, товары и дети
 *    перевешиваются на якорную строку, её поля (linked_at, quantum_*) не затираются,
 *    строка-дубликат удаляется физически вместе со своей метадатой.
 *
 * Все записи — DB::table (без Eloquent-событий: наружу ничего не публикуем).
 * Финальный проход дорезолвливает parent_id по parent_external_id внутри якоря.
 */
class NormalizeCategory1cNetworkScopeCommand extends Command
{
    protected $signature = 'category-1c:normalize-network-scope {--dry-run : Только отчёт, без изменений}';

    protected $description = 'Перенести пер-магазинные категории 1С под якорный магазин сетевого режима';

    public function handle(): int
    {
        $anchorShopId = ModuleConfigService::getCategory1cNetworkAnchorShopId();

        if ($anchorShopId === null) {
            $this->error('Сетевой режим выключен или anchor_shop_id не задан (project_modules.category_1c_network_mapping).');

            return self::FAILURE;
        }

        if (Shop::withTrashed()->find($anchorShopId) === null) {
            $this->error("Якорный магазин #{$anchorShopId} не найден.");

            return self::FAILURE;
        }

        $apply = !$this->option('dry-run');
        $moved = 0;
        $merged = 0;

        Category1c::withTrashed()
            ->where('shop_id', '!=', $anchorShopId)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($anchorShopId, $apply, &$moved, &$merged) {
                foreach ($rows as $row) {
                    $anchorRow = Category1c::withTrashed()
                        ->where('shop_id', $anchorShopId)
                        ->where('external_id', $row->external_id)
                        ->first();

                    if ($anchorRow === null) {
                        $moved++;
                        if ($apply) {
                            DB::table('categories_1c')->where('id', $row->id)->update([
                                'shop_id' => $anchorShopId,
                                'updated_at' => now(),
                            ]);
                        }
                        continue;
                    }

                    $merged++;
                    if ($apply) {
                        $this->mergeIntoAnchor($row, $anchorRow);
                    }
                }
            });

        if ($apply) {
            $this->resolveParentsInsideAnchor($anchorShopId);
        }

        $mode = $apply ? 'применено' : 'dry-run, БЕЗ изменений';
        $this->info("Готово ({$mode}): перенесено {$moved}, слито дубликатов {$merged}.");

        return self::SUCCESS;
    }

    private function mergeIntoAnchor(Category1c $dup, Category1c $anchorRow): void
    {
        DB::transaction(function () use ($dup, $anchorRow) {
            // Pivot дубликата → якорной строке (аддитивно, позиции в хвост).
            $existing = $anchorRow->showcaseCategories()->pluck('categories.id')->all();
            $positions = $anchorRow->showcaseCategories()->pluck('category_1c_category.position')->all();
            $nextPosition = $positions === [] ? 0 : (max($positions) + 1);

            $dupShowcaseIds = DB::table('category_1c_category')
                ->where('categories_1c_id', $dup->id)
                ->orderBy('position')
                ->pluck('category_id')
                ->all();

            foreach ($dupShowcaseIds as $showcaseId) {
                if (in_array((int) $showcaseId, array_map('intval', $existing), true)) {
                    continue;
                }
                DB::table('category_1c_category')->insert([
                    'categories_1c_id' => $anchorRow->id,
                    'category_id' => $showcaseId,
                    'position' => $nextPosition++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('products')
                ->where('category_1c_id', $dup->id)
                ->update(['category_1c_id' => $anchorRow->id, 'updated_at' => now()]);

            DB::table('categories_1c')
                ->where('parent_id', $dup->id)
                ->update(['parent_id' => $anchorRow->id, 'updated_at' => now()]);

            DB::table('event_sync_metadata')
                ->where('entity_type', 'categories_1c')
                ->where('entity_id', $dup->id)
                ->delete();

            // Свои pivot-строки дубликата удалит FK cascade при delete.
            DB::table('categories_1c')->where('id', $dup->id)->delete();
        });
    }

    private function resolveParentsInsideAnchor(int $anchorShopId): void
    {
        Category1c::withTrashed()
            ->where('shop_id', $anchorShopId)
            ->whereNotNull('parent_external_id')
            ->where('parent_external_id', '!=', '')
            ->chunkById(500, function ($rows) use ($anchorShopId) {
                foreach ($rows as $row) {
                    $parent = Category1c::where('shop_id', $anchorShopId)
                        ->where('external_id', $row->parent_external_id)
                        ->first();

                    if ($parent !== null && $row->parent_id !== $parent->id && $parent->id !== $row->id) {
                        DB::table('categories_1c')
                            ->where('id', $row->id)
                            ->update(['parent_id' => $parent->id, 'updated_at' => now()]);
                    }
                }
            });
    }
}
