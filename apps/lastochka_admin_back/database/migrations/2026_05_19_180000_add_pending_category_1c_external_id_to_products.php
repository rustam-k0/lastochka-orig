<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'pending_category_1c_external_id')) {
                $table->string('pending_category_1c_external_id', 64)
                    ->nullable()
                    ->after('category_1c_id');
                $table->index(
                    ['shop_id', 'pending_category_1c_external_id'],
                    'products_shop_pending_cat1c_idx'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'pending_category_1c_external_id')) {
                $table->dropIndex('products_shop_pending_cat1c_idx');
                $table->dropColumn('pending_category_1c_external_id');
            }
        });
    }
};
