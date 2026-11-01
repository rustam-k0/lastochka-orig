<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: the create-table migration may have already added these constraints
        // with short names (when MySQL didn't reject the auto-generated long names).
        $db = DB::getDatabaseName();
        $existingFks = collect(
            DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'merchant_product_group_filter_values'
                 AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$db]
            )
        )->pluck('CONSTRAINT_NAME')->map(fn ($n) => strtolower($n));

        Schema::table('merchant_product_group_filter_values', function (Blueprint $table) use ($existingFks) {
            if (!$existingFks->contains('mpg_fv_group_fk')) {
                $table->foreign('merchant_product_group_id', 'mpg_fv_group_fk')
                    ->references('id')->on('merchant_product_groups')->cascadeOnDelete();
            }
            if (!$existingFks->contains('mpg_fv_value_fk')) {
                $table->foreign('product_group_filter_value_id', 'mpg_fv_value_fk')
                    ->references('id')->on('product_group_filter_values')->cascadeOnDelete();
            }
        });

        // Guard for unique index
        $existingIndexes = collect(
            DB::select("SHOW INDEX FROM merchant_product_group_filter_values WHERE Key_name = 'mpg_filter_value_unique'")
        );

        if ($existingIndexes->isEmpty()) {
            Schema::table('merchant_product_group_filter_values', function (Blueprint $table) {
                $table->unique(
                    ['merchant_product_group_id', 'product_group_filter_value_id'],
                    'mpg_filter_value_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('merchant_product_group_filter_values', function (Blueprint $table) {
            $table->dropForeign('mpg_fv_group_fk');
            $table->dropForeign('mpg_fv_value_fk');
            $table->dropUnique('mpg_filter_value_unique');
        });
    }
};
