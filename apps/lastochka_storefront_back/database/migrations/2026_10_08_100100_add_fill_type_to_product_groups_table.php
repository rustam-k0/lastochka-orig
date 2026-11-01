<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Способ наполнения товарной группы: manual (товары выбраны админом) или
 * автоматический критерий (purchase_history, viewed_not_bought, is_new,
 * is_hurry_up), см. {@see \App\Enums\ProductGroupFillType}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('product_groups', 'fill_type')) {
                $table->string('fill_type', 30)->default('manual')->comment('Способ наполнения группы');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            if (Schema::hasColumn('product_groups', 'fill_type')) {
                $table->dropColumn('fill_type');
            }
        });
    }
};
