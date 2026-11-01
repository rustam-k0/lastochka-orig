<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сырая единица товара из 1С (база для семейства и нормализации цены) — LASTOCHKI-JAMI-11200.
 *
 * Не перетирается квантом (в отличие от info_weight_type_id). Бэкфилл существующих
 * из info_weight_type_id (на момент миграции квант менял только грамм/кг весовым,
 * семейство сохранялось). Штучные без info_weight_type_id → штука.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'base_unit_type_id')) {
                $table->unsignedInteger('base_unit_type_id')->nullable()->after('info_weight_type_id')
                    ->comment('Сырая единица товара из 1С (масса/объём/штуки), не перетирается квантом');
            }
        });

        DB::statement('UPDATE products SET base_unit_type_id = info_weight_type_id WHERE base_unit_type_id IS NULL AND info_weight_type_id IS NOT NULL');

        DB::table('products')
            ->whereNull('base_unit_type_id')
            ->whereNull('info_weight_type_id')
            ->update(['base_unit_type_id' => 1]); // INFO_WEIGHT_PIECE
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'base_unit_type_id')) {
                $table->dropColumn('base_unit_type_id');
            }
        });
    }
};
