<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Цена за наименьшую единицу (₽/г) — 6 знаков вместо 4.
 *
 * Цена 1С в копейках за кг, делённая на 1000, укладывается в 6 знаков точно
 * (199.99 ₽/кг → 0.19999 ₽/г). С 4 знаками пересчёт кванта терял копейки:
 * 0.2000 ₽/г → 200.00 ₽/кг, ручные 200 ₽ за 300 г → 200.01 ₽.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('prices', 'price_per_small_unit')) {
            return;
        }

        DB::statement("ALTER TABLE prices MODIFY price_per_small_unit DECIMAL(16,6) NULL
            COMMENT 'Сырая цена за наименьшую единицу семейства (грамм/мл/штука) — база пересчёта кванта'");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('prices', 'price_per_small_unit')) {
            return;
        }

        DB::statement("ALTER TABLE prices MODIFY price_per_small_unit DECIMAL(14,4) NULL
            COMMENT 'Сырая цена за наименьшую единицу семейства (грамм/мл/штука) — база пересчёта кванта'");
    }
};
