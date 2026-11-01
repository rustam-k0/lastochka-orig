<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Обобщение кванта категории под единицы (масса/объём/штуки) — LASTOCHKI-JAMI-11200.
 *
 * quantum_grams → quantum_step (шаг в наименьшей единице семейства: грамм/мл/штука)
 * + quantum_unit_type_id (2=грамм / 5=мл / 1=шт). Существующие кванты были в граммах.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'quantum_grams') && !Schema::hasColumn('categories', 'quantum_step')) {
                $table->renameColumn('quantum_grams', 'quantum_step');
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            if (!Schema::hasColumn('categories', 'quantum_unit_type_id')) {
                $table->unsignedInteger('quantum_unit_type_id')->nullable()->after('quantum_step')
                    ->comment('Единица кванта: 2=грамм, 5=мл, 1=шт (наименьшая в семействе)');
            }
        });

        DB::table('categories')
            ->whereNotNull('quantum_step')
            ->whereNull('quantum_unit_type_id')
            ->update(['quantum_unit_type_id' => 2]); // INFO_WEIGHT_GRAM
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'quantum_unit_type_id')) {
                $table->dropColumn('quantum_unit_type_id');
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'quantum_step') && !Schema::hasColumn('categories', 'quantum_grams')) {
                $table->renameColumn('quantum_step', 'quantum_grams');
            }
        });
    }
};
