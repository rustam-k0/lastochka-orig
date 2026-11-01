<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('product_groups', 'is_complex_solution')) {
            return;
        }

        Schema::table('product_groups', function (Blueprint $table) {
            $table->boolean('is_complex_solution')->default(false)->comment('Комплексное решение (фасетный каталог вместо мест размещения)');

            $table->index('is_complex_solution');
        });

        // Backfill: группы с привязанными значениями фильтров — де-факто комплексные
        // решения. Помечаем флагом и сбрасываем места размещения (взаимоисключающие).
        $solutionIds = DB::table('product_group_filter_value')
            ->distinct()
            ->pluck('product_group_id');

        if ($solutionIds->isNotEmpty()) {
            DB::table('product_groups')
                ->whereIn('id', $solutionIds)
                ->update([
                    'is_complex_solution' => true,
                    'show_on_home' => false,
                    'show_on_store_page' => false,
                    'show_in_catalog' => false,
                    'show_on_search' => false,
                    'is_recommended' => false,
                    'is_cart_page' => false,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropIndex(['is_complex_solution']);
            $table->dropColumn('is_complex_solution');
        });
    }
};
