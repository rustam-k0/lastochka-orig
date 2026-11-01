<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_product_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_product_groups', 'fill_type')) {
                $table->string('fill_type', 30)->default('manual')
                    ->comment('Способ наполнения: manual | purchase_history | viewed_not_bought | is_new | is_hurry_up');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchant_product_groups', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_product_groups', 'fill_type')) {
                $table->dropColumn('fill_type');
            }
        });
    }
};
