<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Делает orders.shop_id nullable (aggregator-режим, FOODLINK-10874, §6.5).
 *
 * После split'а по магазинам родительский заказ становится чисто координационным
 * (shop_id=NULL), фактическая выдача идёт через дочерние. Безопасная релаксация
 * NOT NULL → NULL: legacy/форки всегда заполняют shop_id, поэтому поведение standard/
 * marketplace не меняется. FK orders_shop_id_foreign совместим с nullable-колонкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        DB::statement('ALTER TABLE orders MODIFY COLUMN shop_id BIGINT UNSIGNED NULL DEFAULT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        // Откат безопасен только если нет координационных заказов с NULL shop_id
        // (иначе вернуть NOT NULL без нарушения FK нельзя — пропускаем).
        $hasNull = DB::table('orders')->whereNull('shop_id')->exists();
        if (!$hasNull) {
            DB::statement('ALTER TABLE orders MODIFY COLUMN shop_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
