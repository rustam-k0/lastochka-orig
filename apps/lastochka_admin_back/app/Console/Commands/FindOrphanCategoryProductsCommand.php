<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Находит активные товары, у которых ВСЕ категории в pivot `category_product`
 * soft-deleted. Такие товары выпадают из клиентского каталога (storefront
 * фильтрует по живым категориям), но остаются в БД — требуют решения менеджера
 * о перепривязке.
 *
 * Пример:
 *   php artisan products:find-orphan-categories --shop=3
 *   php artisan products:find-orphan-categories --format=csv > /tmp/orphans.csv
 */
class FindOrphanCategoryProductsCommand extends Command
{
    protected $signature = 'products:find-orphan-categories
                            {--shop= : Ограничить одним shop_id}
                            {--format=table : table|json|csv}';

    protected $description = 'Список живых товаров, у которых все категории в pivot soft-deleted';

    public function handle(): int
    {
        $shopId = $this->option('shop') !== null ? (int) $this->option('shop') : null;
        $format = (string) $this->option('format');

        if (! in_array($format, ['table', 'json', 'csv'], true)) {
            $this->error("Unknown --format={$format}. Use: table|json|csv");

            return self::FAILURE;
        }

        $sub = DB::table('category_product as cp')
            ->join('categories as c', 'c.id', '=', 'cp.category_id')
            ->select('cp.product_id')
            ->selectRaw('COUNT(c.id) AS total_cats')
            ->selectRaw('SUM(CASE WHEN c.deleted_at IS NULL THEN 1 ELSE 0 END) AS alive_cats')
            ->selectRaw(
                "GROUP_CONCAT(CONCAT(c.id, ':', COALESCE(c.title, 'NULL')) SEPARATOR ' | ') AS dead_cats"
            )
            ->groupBy('cp.product_id');

        $query = DB::table('products as p')
            ->joinSub($sub, 'cats', 'cats.product_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->where('cats.alive_cats', 0)
            ->select([
                'p.id',
                'p.shop_id',
                'p.articul',
                'p.title',
                'cats.total_cats',
                'cats.dead_cats',
            ])
            ->orderBy('p.shop_id')
            ->orderBy('p.id');

        if ($shopId !== null) {
            $query->where('p.shop_id', $shopId);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('Осиротевшие товары не найдены.');

            return self::SUCCESS;
        }

        $out = $rows->map(fn ($row) => [
            'product_id' => (int) $row->id,
            'shop_id' => $row->shop_id !== null ? (int) $row->shop_id : null,
            'articul' => $row->articul,
            'title' => $row->title,
            'total_cats' => (int) $row->total_cats,
            'dead_cats' => $row->dead_cats,
        ])->all();

        match ($format) {
            'json' => $this->line((string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            'csv' => $this->printCsv($out),
            default => $this->table(
                ['id', 'shop_id', 'articul', 'title', 'total', 'dead_cats'],
                array_map(fn ($r) => [
                    $r['product_id'],
                    $r['shop_id'],
                    $r['articul'],
                    $r['title'],
                    $r['total_cats'],
                    $r['dead_cats'],
                ], $out)
            ),
        };

        $this->info(sprintf('Всего найдено: %d', count($out)));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function printCsv(array $rows): void
    {
        $fh = fopen('php://stdout', 'w');
        if ($fh === false) {
            return;
        }

        fputcsv($fh, ['product_id', 'shop_id', 'articul', 'title', 'total_cats', 'dead_cats']);

        foreach ($rows as $row) {
            fputcsv($fh, [
                $row['product_id'],
                $row['shop_id'],
                $row['articul'],
                $row['title'],
                $row['total_cats'],
                $row['dead_cats'],
            ]);
        }

        fclose($fh);
    }
}
