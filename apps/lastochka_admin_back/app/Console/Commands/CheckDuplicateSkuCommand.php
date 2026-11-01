<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckDuplicateSkuCommand extends Command
{
    protected $signature = 'products:check-duplicate-sku {--fix : Автоматически проставить суффикс _dup2, _dup3 дублям}';

    protected $description = 'Ищет товары с одинаковым articul в одном shop_id. Нужно прогнать перед миграцией add_unique_articul_shop_products.';

    public function handle(): int
    {
        $duplicates = DB::table('products')
            ->select('shop_id', 'articul', DB::raw('COUNT(*) as cnt'), DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'))
            ->whereNull('deleted_at')
            ->whereNotNull('articul')
            ->where('articul', '!=', '')
            ->groupBy('shop_id', 'articul')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('Дубли articul внутри shop_id не найдены. Можно применять миграцию products_articul_shop_unique.');

            return self::SUCCESS;
        }

        $this->warn("Найдено дублей: {$duplicates->count()}");

        $rows = [];
        foreach ($duplicates as $duplicate) {
            $rows[] = [$duplicate->shop_id, $duplicate->articul, $duplicate->cnt, $duplicate->ids];
        }
        $this->table(['shop_id', 'articul', 'count', 'product_ids'], $rows);

        if (!$this->option('fix')) {
            $this->line('');
            $this->line('Запусти с --fix чтобы автоматически проставить суффиксы _dup2, _dup3 дублям (оставив первый ID как есть).');

            return self::FAILURE;
        }

        $fixed = 0;
        foreach ($duplicates as $duplicate) {
            $ids = array_map('intval', explode(',', $duplicate->ids));
            array_shift($ids);

            foreach ($ids as $idx => $productId) {
                $suffix = '_dup' . ($idx + 2);
                $newArticul = $duplicate->articul . $suffix;

                DB::table('products')
                    ->where('id', $productId)
                    ->update(['articul' => $newArticul]);

                $fixed++;
            }
        }

        $this->info("Исправлено записей: {$fixed}. Теперь можно запускать миграцию.");

        return self::SUCCESS;
    }
}
