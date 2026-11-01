<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Обобщение ручного кванта товара под единицы — LASTOCHKI-JAMI-11200.
 *
 * manual_quantum_grams → manual_quantum_step + manual_quantum_unit_type_id
 * (2=грамм / 5=мл / 1=шт). Существующие были в граммах.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'manual_quantum_grams') && !Schema::hasColumn('products', 'manual_quantum_step')) {
                $table->renameColumn('manual_quantum_grams', 'manual_quantum_step');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'manual_quantum_unit_type_id')) {
                $table->unsignedInteger('manual_quantum_unit_type_id')->nullable()->after('manual_quantum_step')
                    ->comment('Единица ручного кванта: 2=грамм, 5=мл, 1=шт');
            }
        });

        DB::table('products')
            ->whereNotNull('manual_quantum_step')
            ->whereNull('manual_quantum_unit_type_id')
            ->update(['manual_quantum_unit_type_id' => 2]); // INFO_WEIGHT_GRAM
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'manual_quantum_unit_type_id')) {
                $table->dropColumn('manual_quantum_unit_type_id');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'manual_quantum_step') && !Schema::hasColumn('products', 'manual_quantum_grams')) {
                $table->renameColumn('manual_quantum_step', 'manual_quantum_grams');
            }
        });
    }
};
