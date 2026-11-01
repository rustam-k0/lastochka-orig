<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сетевой каталог: цены хранятся пер-магазин (product_id, shop_id, type_id).
 * Колонка shop_id живёт в БД вне миграционной истории admin_back (наследие
 * store_back) — добавляем с guard'ом для форков, где её может не быть.
 * Индекс нужен консьюмеру (processPrice lockForUpdate по тройке) и выдаче
 * цен по магазину. Аддитивно, идемпотентно, форк-безопасно.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('prices', 'shop_id')) {
            Schema::table('prices', function (Blueprint $table) {
                $table->unsignedBigInteger('shop_id')->nullable()->after('product_id');
            });
        }

        $exists = !empty(DB::select(
            "SHOW INDEX FROM `prices` WHERE Key_name = 'prices_product_shop_type_index'"
        ));
        if (!$exists) {
            Schema::table('prices', function (Blueprint $table) {
                $table->index(['product_id', 'shop_id', 'type_id'], 'prices_product_shop_type_index');
            });
        }
    }

    public function down(): void
    {
        // Составной индекс покрывает FK product_id -> products своим первым
        // столбцом. Если однoколоночный индекс FK был вытеснен/отсутствует,
        // dropIndex ниже упадёт с MySQL-ошибкой 1553 (index needed in a
        // foreign key constraint) — восстанавливаем его перед дропом.
        $fkIndexExists = !empty(DB::select(
            "SHOW INDEX FROM `prices` WHERE Key_name = 'prices_product_id_foreign'"
        ));
        if (!$fkIndexExists) {
            Schema::table('prices', function (Blueprint $table) {
                $table->index('product_id', 'prices_product_id_foreign');
            });
        }

        $exists = !empty(DB::select(
            "SHOW INDEX FROM `prices` WHERE Key_name = 'prices_product_shop_type_index'"
        ));
        if ($exists) {
            Schema::table('prices', function (Blueprint $table) {
                $table->dropIndex('prices_product_shop_type_index');
            });
        }
        // Колонку shop_id НЕ дропаем: на неё завязан работающий код (processPrice).
    }
};
