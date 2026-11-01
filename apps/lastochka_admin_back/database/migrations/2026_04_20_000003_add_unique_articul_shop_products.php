<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'shop_id') || !Schema::hasColumn('products', 'articul')) {
            return;
        }

        if ($this->indexExists('products', 'products_articul_shop_unique')) {
            return;
        }

        // Дедупликация: ищем дубли по (shop_id, articul) среди ВСЕХ записей
        // (включая soft-deleted), потому что MySQL UNIQUE не умеет partial index
        // WHERE deleted_at IS NULL. Оставляем запись с минимальным id, остальным
        // дописываем суффикс _dup2/_dup3/... к articul.
        $duplicates = DB::table('products')
            ->select('shop_id', 'articul', DB::raw('COUNT(*) as cnt'), DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'))
            ->whereNotNull('articul')
            ->where('articul', '!=', '')
            ->groupBy('shop_id', 'articul')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = array_map('intval', explode(',', $duplicate->ids));
            array_shift($ids);

            foreach ($ids as $productId) {
                // Суффикс по id гарантирует уникальность даже при повторных проходах
                // и при коллизии с уже-переименованными записями (например после
                // ручного artisan products:check-duplicate-sku --fix).
                $newArticul = $duplicate->articul . '_dup' . $productId;
                DB::table('products')
                    ->where('id', $productId)
                    ->update(['articul' => $newArticul]);
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['shop_id', 'articul'], 'products_articul_shop_unique');
        });
    }

    public function down(): void
    {
        if ($this->indexExists('products', 'products_articul_shop_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique('products_articul_shop_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return (bool) $connection->selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );
    }
};
