<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `products_articul_shop_unique` — составной (shop_id, articul), leftmost-prefix
 * не покрывает запросы по одному articul без shop_id. Такие запросы (например
 * ProductEventTransformer::resolveUniqueArticul — обязательная проверка перед
 * созданием товара на предмет коллизии SKU из 1С) делают full table scan,
 * который линейно деградирует с ростом products. Добавляем отдельный индекс
 * по articul для этого паттерна запросов.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'articul')) {
            return;
        }

        if ($this->indexExists('products', 'products_articul_index')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->index('articul', 'products_articul_index');
        });
    }

    public function down(): void
    {
        if ($this->indexExists('products', 'products_articul_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_articul_index');
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
