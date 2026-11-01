<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сетевой каталог: остатки читаются/пишутся по (product_id, shop_id) —
 * составной индекс для консьюмера (processQuantity lockForUpdate) и
 * выборок остатков магазина. Аддитивно, идемпотентно.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = !empty(DB::select(
            "SHOW INDEX FROM `remains` WHERE Key_name = 'remains_product_shop_index'"
        ));
        if (!$exists) {
            Schema::table('remains', function (Blueprint $table) {
                $table->index(['product_id', 'shop_id'], 'remains_product_shop_index');
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
            "SHOW INDEX FROM `remains` WHERE Key_name = 'remains_product_id_foreign'"
        ));
        if (!$fkIndexExists) {
            Schema::table('remains', function (Blueprint $table) {
                $table->index('product_id', 'remains_product_id_foreign');
            });
        }

        $exists = !empty(DB::select(
            "SHOW INDEX FROM `remains` WHERE Key_name = 'remains_product_shop_index'"
        ));
        if ($exists) {
            Schema::table('remains', function (Blueprint $table) {
                $table->dropIndex('remains_product_shop_index');
            });
        }
    }
};
