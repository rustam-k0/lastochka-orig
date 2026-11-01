<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Отдельная колонка price_per_small_unit для адаптивного кванта (LASTOCHKI-JAMI-11200).
 *
 * price_per_kg НЕ трогаем — это shared-колонка, её читают purchase_back
 * (OrderCreatorService::getPriceDuringOrder для весовых) и delivery_back.
 * Добавляем новую колонку: сырая цена, нормализованная к цене за наименьшую
 * единицу семейства (за грамм/мл/штуку). Точность 4 знака — цена за грамм мелкая
 * (411 ₽/кг → 0.4110 ₽/г), иначе пересчёт кванта теряет копейки.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            if (!Schema::hasColumn('prices', 'price_per_small_unit')) {
                $table->decimal('price_per_small_unit', 14, 4)->nullable()->after('price_per_kg')
                    ->comment('Сырая цена за наименьшую единицу семейства (грамм/мл/штука) — база пересчёта кванта');
            }
        });

        // Бэкфилл price_per_small_unit (цена за наименьшую единицу семейства),
        // family-aware по base_unit_type_id (проставлен в предыдущей миграции):
        //  - кг-база (3): price_per_kg за кг → за грамм (÷1000); fallback value/1000;
        //  - грамм-база (2): value уже за грамм;
        //  - штучная база (1): value за штуку (НЕ ÷1000 — иначе цена в 1000× меньше);
        //  - объём (4,5) отложен — оставляем NULL.
        DB::statement('UPDATE prices p JOIN products pr ON pr.id = p.product_id
            SET p.price_per_small_unit = ROUND(COALESCE(p.price_per_kg, p.value) / 1000, 4)
            WHERE pr.base_unit_type_id = 3');
        DB::statement('UPDATE prices p JOIN products pr ON pr.id = p.product_id
            SET p.price_per_small_unit = p.value
            WHERE pr.base_unit_type_id IN (1, 2)');
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            if (Schema::hasColumn('prices', 'price_per_small_unit')) {
                $table->dropColumn('price_per_small_unit');
            }
        });
    }
};
